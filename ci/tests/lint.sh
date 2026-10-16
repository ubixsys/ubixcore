#!/usr/bin/env bash
# Lint the shared CI template. Run from anywhere in the repo.
#   1. yamllint on ci/
#   2. glab ci lint on the template itself (a valid standalone config)
#   3. rules.py: dry-runs the template per tier/variable scenario and checks the job set
#   4. glab ci lint on the example consumer, which includes the template by `local:`.
#      GitLab resolves `local:` against the pushed ref, so this runs only when the
#      current branch exists on origin (push first).
# Steps 2-4 need glab, authenticated against the instance; they are skipped with
# --offline or when glab is absent.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

yamllint -c ci/tests/.yamllint.yml ci/

if [ "${1:-}" = "--offline" ]; then exit 0; fi
if ! command -v glab >/dev/null 2>&1; then echo "glab not installed: skipping glab checks"; exit 0; fi

glab ci lint ci/ubixcore-pipeline.yml
python3 -I ci/tests/rules.py

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if git ls-remote --exit-code --heads origin "$BRANCH" >/dev/null 2>&1; then
  glab ci lint --ref "$BRANCH" ci/tests/consumer.gitlab-ci.yml
else
  echo "branch $BRANCH is not on origin: skipping the consumer lint (push first)"
fi
