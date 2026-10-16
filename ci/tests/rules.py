#!/usr/bin/env python3
"""Behavioural check of the template's rules: for each scenario, override variables in a
copy of ci/ubixcore-pipeline.yml, ask GitLab to dry-run the pipeline on the project's
default branch (`main`) through the CI lint API, and compare the job set and `when:`.

The default branch plays whichever tier the scenario points it at, so one ref covers
dev, staging and prod. Needs `glab` authenticated against the instance.
Run:  python3 -I ci/tests/rules.py
"""
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
PROJECT = os.environ.get("UBIX_LINT_PROJECT", "ubixsys/ubixcore").replace("/", "%2F")
NONE = "no-such-branch"

TEMPLATE = open(os.path.join(ROOT, "ci", "ubixcore-pipeline.yml")).read()


def jobs(overrides):
    text = TEMPLATE
    for key, value in overrides.items():
        text, n = re.subn(rf'^  {key}: .*$', f'  {key}: "{value}"', text, flags=re.M)
        assert n == 1, f"variable {key} not found in template"
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
        json.dump({"content": text, "dry_run": True, "include_jobs": True}, f)
    out = subprocess.run(
        ["glab", "api", f"projects/{PROJECT}/ci/lint", "-X", "POST",
         "-H", "Content-Type: application/json", "--input", f.name],
        capture_output=True, text=True, check=True).stdout
    os.unlink(f.name)
    data = json.loads(out)
    if any(("would have been empty" in e or "did not run" in e) for e in data["errors"]):
        return {}
    assert data["valid"], data["errors"]
    return {j["name"]: j["when"] for j in data["jobs"]}


def only(tier):
    branches = {"dev": NONE, "staging": NONE, "prod": NONE}
    branches[tier] = "main"
    return {"UBIX_DEV_BRANCH": branches["dev"], "UBIX_STAGING_BRANCH": branches["staging"],
            "UBIX_PROD_BRANCH": NONE if tier != "prod" else "main"}


failures = []


def check(name, got, present=(), absent=(), when=None):
    for j in present:
        if j not in got:
            failures.append(f"{name}: expected job {j}")
    for j in absent:
        if j in got:
            failures.append(f"{name}: unexpected job {j}")
    for j, w in (when or {}).items():
        if got.get(j) != w:
            failures.append(f"{name}: {j} when={got.get(j)!r}, expected {w!r}")


# dev
g = jobs(only("dev"))
check("dev", g,
      present=["build-dev", "static-checks-dev", "phpunit-dev", "migrate-verify-dev", "migrate-apply-dev",
               "deploy-dev", "promote-to-staging", "promote-to-staging-destructive", "notify-failure-dev"],
      absent=["build-staging", "build-prod", "deploy-prod", "drift-dev", "migrate-apply-dev-destructive",
              "build-mr", "js-lint-dev", "go-checks-dev"],
      when={"migrate-apply-dev": "on_success", "deploy-dev": "on_success",
            "promote-to-staging": "on_success", "promote-to-staging-destructive": "manual",
            "notify-failure-dev": "on_failure"})

# staging
g = jobs(only("staging"))
check("staging", g,
      present=["build-staging", "migrate-apply-staging", "migrate-apply-staging-destructive", "deploy-staging",
               "promote-to-prod", "promote-to-prod-destructive", "notify-failure-staging"],
      absent=["build-dev", "build-prod", "deploy-prod"],
      when={"migrate-apply-staging-destructive": "manual", "promote-to-prod": "on_success"})

# prod: manual migrate-apply and deploy by default
g = jobs(only("prod"))
check("prod", g,
      present=["build-prod", "migrate-verify-prod", "migrate-apply-prod", "migrate-apply-prod-destructive",
               "deploy-prod", "notify-failure-prod"],
      absent=["build-dev", "build-staging", "promote-to-staging", "promote-to-prod"],
      when={"migrate-apply-prod": "manual", "deploy-prod": "manual", "notify-failure-prod": "on_failure"})

# prod auto-deploy
g = jobs({**only("prod"), "UBIX_PROD_DEPLOY_WHEN": "on_success"})
check("prod on_success", g, present=["deploy-prod"], when={"deploy-prod": "on_success"})

# drift on
g = jobs({**only("dev"), "UBIX_DRIFT_ENABLED": "true"})
check("drift", g, present=["drift-dev"])

# no database
g = jobs({**only("dev"), "UBIX_MIGRATE_ENABLED": "false", "UBIX_DRIFT_ENABLED": "true"})
check("migrate off", g, present=["build-dev", "deploy-dev"],
      absent=["migrate-verify-dev", "migrate-apply-dev", "drift-dev"])

# a host that drops the staging tier
g = jobs({**only("staging"), "UBIX_TIERS": "dev,prod"})
check("tiers", g, absent=["build-staging", "deploy-staging", "migrate-apply-staging", "promote-to-prod"])

# a feature branch gets nothing
g = jobs({"UBIX_DEV_BRANCH": NONE, "UBIX_STAGING_BRANCH": NONE, "UBIX_PROD_BRANCH": NONE})
check("no tier branch", g, absent=["build-dev", "build-staging", "build-prod", "deploy-prod"])

if failures:
    print("\n".join(failures))
    sys.exit(1)
print("rules OK")
