# Design: one standard GitLab CI pipeline template for uBixCore apps

Status: accepted; implemented in `ci/ubixcore-pipeline.yml` (lane `ci/pipeline-template`, item 103).
Where the implementation differs from the original proposal, this document now describes the
implementation. Original lane `docs/ci-pipeline-template`. Source pipelines read on 2026-10-15:
kitg `.gitlab-ci.yml` (836 lines), project-neptune `.gitlab-ci.yml` (5355 lines), this
repo's `.gitlab-ci.yml` (222 lines) and `skeleton/.gitlab-ci.yml` (325 lines). Neither
kitg nor neptune uses `include:`; every host today carries a full copy.

## 1. Goal, scope, non-goals

**Goal.** One pipeline definition, versioned with the framework, that gives any uBixCore
host build → test → migrate → deploy → promote → notify across dev, staging and prod, so a
fix to (say) the migrate job lands in every host by bumping a ref instead of by hand-porting.

**In scope:** the shared stages and jobs, their variables, the branch/MR rules, what a host
must provide.

**Non-goals (stay per-app):** Dockerfile contents, k8s manifests, the app's own domain gates
(neptune's Lighthouse thresholds, DAST baselines, decision-register), vault path contents,
runner provisioning.

## 2. Inventory of the pipelines that work

### 2.1 kitg

Stages: `test, build, lint-and-test, migrate, deploy, promote, maintenance, notify, approval`.
Runner tag `shell` everywhere. No `image:` — jobs run `docker buildx` / `docker run` on the
runner host. Workflow rules: MR events, schedules, and pushes to `dev|staging|main|master`.
`default:` is `interruptible: true` with retry x2 on infra-class failures.

| Job (per tier unless noted) | Stage | Rules | What it does |
|---|---|---|---|
| `build-dev` / `-staging` / `-main` | build | `only: dev` / `staging` / `main,master` | buildx runtime image (`Dockerfile`, `Dockerfile_Staging`, `Dockerfile_Main`), a `-test` image (`Dockerfile_Test`) layered on it, plus `-node` / `-go` images per `app/*Js` / `app/*Go`. Composer auth from vault else `CI_JOB_TOKEN`. Retries script failures too. |
| `build-mr` | build | MR | same, `linux/amd64` only |
| `static-checks-*` | lint-and-test | per tier + `-mr` | phpcs + phpstan in the `-test` image |
| `phpunit-*` | lint-and-test | per tier; `phpunit-mr` | resets the test schema from vault `test-db`, runs `migrate:up --target=test`, then phpunit. MR variant runs phpunit only. |
| `js-lint-*`, `go-checks-*` | lint-and-test | per tier + `-mr` | npm lint/check/test, golangci-lint/go test per app dir |
| `ai-review-mr` | lint-and-test | MR, `allow_failure` | `ci:aiReview` on the MR diff; skips if keys unset |
| `require-approval-mr` | approval | MR | `ci:requireApproval`: owner Approve/👍 gate (`MERGE_APPROVAL_OWNERS`) |
| `migrate-dev` / `-staging` | migrate | per tier | host cross-check (vault db host vs `app/*/<tier>-deploy.yaml`), `migrate:status` + `migrate:up`, app grants, backup copy, Discord |
| `migrate-main` | migrate | main, **manual**, not allow_failure | same, tier `prod` |
| `migrate-{staging,main}-destructive` | migrate | manual | same with `--i-acknowledge-destructive` |
| `drift-*` | migrate | after migrate | replay migrations into `REPLAY_` schemas and diff against live |
| `relabel-staging-database` | migrate | staging, manual | `database:label` prod → staging |
| `deploy-dev/-staging/-main` | deploy | per tier, `environment:` | `bash bin/deploy.sh $CI_COMMIT_REF_SLUG` |
| `promote-to-staging`, `promote-to-main` (+ `-destructive`) | promote | on dev / staging | `bin/promote.sh` fast-forwards the next branch; destructive variant manual |
| `purge-pii-audits` | maintenance | schedule + `MAINTENANCE_TASK` | `cron:<task>` against a tier |
| `notify-failure-*` | notify | `when: on_failure` | Discord `alerts` |

Variables: `RUNTIME_DOCKERFILE`, `BUILD_PLATFORMS`, `MIGRATE_TIER`, `MIGRATE_ACK`,
`APP_DB_HOST`, `MERGE_APPROVAL_OWNERS`, `MAINTENANCE_TASK`/`MAINTENANCE_TIER`,
`GEMINI_API_KEY`, `AI_REVIEW_GITLAB_TOKEN`, `AI_REVIEW_MODEL`; credentials come from
uBixVault via `bin/lib/notify.sh` (`vault_kv`) with `UBIXVAULT_CI_TOKEN_DEV/PROD`.

### 2.2 project-neptune

Stages: `test, build, lint-and-test, migrate-verify, seed, deploy, promote, cleanup, notify`.
Two runner classes: `neptune` (k8s + dind: anything that builds or runs an image, holds the
registry login) and `shell` (host with kubectl + kubeconfigs: deploy/promote only, no
registry login so a registry outage cannot fail a deploy). Job count is roughly 100; the
core that mirrors kitg is:

| Job | Stage | Rules | What it does |
|---|---|---|---|
| `build-dev` / `-staging` / `build-prod` | build | `only: dev / staging / main,master` | runtime image (`Dockerfile` for dev, `Dockerfile_Prod` for staging/prod), `-test` image, `-node` image per SvelteKit app; tags `:<slug>`, `:<sha>`, `:<slug>-<sha>`; BuildKit cache on Harbor with GitLab-registry fallback and `ignore-error=true`; shadow `.env` render check on dev |
| `static-checks-*`, `phpunit-*`, `js-lint-*`, `js-test-*`, `py-checks-*`, `go-checks-*` | lint-and-test | per tier | as kitg, plus Python and JS test jobs |
| `migrate-verify-dev/-staging/-prod` | migrate-verify | per tier | read-only `migrate:status --verify` |
| `migrate-apply-dev/-staging/-prod` | migrate-verify | dev/staging auto, prod manual | `migrate:up` without ack, so a pending destructive migration **holds**; exit code converted to a visible hold |
| `migrate-apply-*-destructive` | migrate-verify | manual | same wrapper with the ack flag |
| `seed-apply-dev/-staging` | seed | per tier | reference-data seed |
| `deploy-dev` / `deploy-staging` | deploy | auto | `bin/deploy.sh`; on `shell` runner |
| `deploy-prod` (+ `-destructive`) | deploy | main, **manual**, `environment: production` | `needs: build-prod, migrate-verify-prod` |
| `deploy-*-emergency`, `emergency-rollback-*`, `auto-rollback-prod*` | deploy | manual / scripted | bypass lint-and-test; image rollback by `<slug>-<sha>` tag |
| `promote-to-staging` (+ `-manual`), `promote-to-main` | promote | dev / staging | fast-forward mirror via `bin/promote-to-staging.sh`; needs `deploy-dev` + `lighthouse-dev` |
| `lighthouse-*`, `dast-*`, `smoke-prod`, `gitleaks-*`, `cspell-knip-*`, `openapi-drift-dev` | lint-and-test / deploy | per tier | extra quality and security gates |
| `claude-review-mr`, `second-opinion-review-mr`, `review-triage-mr`, `require-approval`, `owned-paths-notice`, `mr-size-notice`, `mr-app-labels`, `destructive-pr-detect`, `decision-register-mr` | test | MR | review, sign-off and MR hygiene |
| `cleanup-sha-tags`, `docs-publish-dev`, `code-churn-record-dev`, `production-deploy-record-*`, `notify-failure-dev`, `release-notify` | cleanup / notify | per tier | housekeeping and notifications (Slack) |

Notable variables: `CACHE_IMAGE` / `HARBOR_*`, `NEPTUNE_VAULT_DEV_CI_TOKEN`,
`MIGRATE_ACK_FLAG`, `NEPTUNE_MIGRATION_BACKUP_DIR`, `TARGET`, `APP_NAME`,
`CLAUDE_REVIEW_GITLAB_TOKEN`, `SLACK_API_ENDPOINT`.

### 2.3 Where they differ

| Concern | kitg | neptune | Template decision |
|---|---|---|---|
| Runner | `shell` for everything | `neptune` (build/test/migrate) + `shell` (kubectl) | Two overridable tags: `UBIX_BUILD_TAG`, `UBIX_DEPLOY_TAG`; default both `shell` |
| Registry login | all jobs | build-side only | Login only in image jobs (neptune's rule) |
| Prod Dockerfile | `Dockerfile_Main` | `Dockerfile_Prod` | `UBIX_RUNTIME_DOCKERFILE_{DEV,STAGING,PROD}` |
| Migrate | one job `migrate`+`drift` | split verify / apply, hold-on-destructive | Adopt split: `migrate-verify` (read-only, gates prod deploy) + `migrate-apply`; keep kitg's host cross-check and drift as defaults |
| Prod deploy | auto on main, prod migrate manual | prod deploy manual, protected environment | `UBIX_PROD_DEPLOY_WHEN` = `manual` (default) or `on_success`; always `environment: production` |
| Image tags | `:<slug>`, `:<slug>-<sha>`, `:<sha>-test` | adds bare `:<sha>`, sha cleanup job | `:<slug>`, `:<slug>-<sha>`, `:<sha>-test`; sha cleanup optional |
| Build cache | none | Harbor registry cache | Optional via `UBIX_CACHE_IMAGE` |
| MR pipelines | `-mr` copies of build/check jobs | MR review/notice jobs; branch jobs `only:` | `workflow:rules` as kitg; MR jobs run checks only |
| Review/gates | AI review + approval | Claude review, triage, second opinion, Lighthouse, DAST, smoke | Core ships approval + AI review; the rest are opt-in extensions |
| Notify | Discord | Slack | Notify via the app's `bin/lib/notify.sh`; template only calls it |
| Retry | infra + (build) script failures | per job | `default:` retry as kitg |
| Syntax | `only:/except:` plus some `rules:` | mostly `only:/except:` and `rules:` | New template uses `rules:` only |

Both share the same spine: per-tier hidden templates, `-dev/-staging/-main` triplets,
fast-forward promote scripts, destructive-migration acknowledgement, and deploy through
`bin/deploy.sh <slug>`.

## 3. Proposed template

### 3.1 Delivery

A single file in the framework repo, `ci/ubixcore-pipeline.yml` (`ci/extensions/*.yml` for
opt-in jobs is deferred, see §3.8), consumed by hosts with:

```yaml
include:
  - project: ubixsys/ubixcore
    ref: v0.46.0            # pinned to a release tag; bump with the composer update
    file: ci/ubixcore-pipeline.yml
```

Pinned to a `v*` tag, never `main`, so a host upgrades the pipeline in the same commit as
`composer update ubixsys/ubixcore`. The job-token allowlist from `skeleton/docs/ci-setup.md`
§2 already covers cross-project include access; the include needs the host to be able to
read `ubixsys/ubixcore` (verify for hosts outside the `ubixsys` group, e.g. neptune on a
different GitLab instance, which cannot use `project:` includes and would need `remote:` or a
vendored copy — see §5).

### 3.2 Stages

```
build → test → migrate → deploy → promote → notify
```

plus optional `approval` (MR sign-off) and `maintenance` (scheduled `cron:*` tasks).
The shipped order is `build, test, migrate, deploy, promote, notify, approval, maintenance`:
`approval` comes after `notify` so `notify-failure-*` does not page for every push that is
merely not yet approved. `lint-and-test` is renamed `test`; hosts that still use the old name
keep working by overriding `stages:` (§3.5). Needs are explicit: `test` and `migrate-verify`
jobs `needs: build-<tier>`; `migrate-apply-<tier>` also needs the tier's static-checks,
phpunit and (when present) js/go jobs, so no schema changes under red code; `deploy-<tier>`
`needs:` build, `migrate-verify`, `migrate-apply` (all optional, absent with
`UBIX_MIGRATE_ENABLED=false`) and the test jobs. Every tier, not only prod, waits for its
`migrate-apply`, so a held destructive migration stops the deploy.

### 3.3 Jobs shipped in the core

| Job | Stage | Rules | Notes |
|---|---|---|---|
| `.ubix_registry_login` | – | – | hidden; `before_script` registry login |
| `build-{dev,staging,prod}` | build | branch = tier branch | runtime + `-test` image; node/go images per `app/*Js` / `app/*Go` |
| `build-mr` | build | MR | amd64 only |
| `static-checks-*` | test | tier branch, MR | phpcs + phpstan |
| `phpunit-*` | test | tier branch, MR | tier jobs use vault `test-db` and reset the schema; `phpunit-mr` runs the suite only (the vault token is a protected variable, absent on feature branches) |
| `js-lint-*`, `go-checks-*` | test | tier branch, MR | exist only if `app/*Js/package.json` / `app/*Go/go.mod` exist (`rules:exists`) |
| `migrate-verify-*` | migrate | tier branch | read-only `migrate:status --verify`; host cross-check first; gates deploy |
| `migrate-apply-{dev,staging}` | migrate | tier branch | `migrate:up` without ack; on staging/prod a pending destructive migration **holds** (job fails with a `HELD:` banner and an orange #ops post); dev applies destructive migrations |
| `migrate-apply-prod` | migrate | prod branch, manual, blocking | `deploy-prod` waits for it |
| `migrate-apply-{staging,prod}-destructive` | migrate | manual, `allow_failure` | ack flag; afterwards retry `migrate-apply-<tier>` to unblock the deploy. No dev variant: dev never holds |
| `drift-*` | migrate | after apply | optional (`UBIX_DRIFT_ENABLED`); drops `REPLAY_SYSTEMS` and `REPLAY_<tier database>` (more via the job variable `UBIX_DRIFT_EXTRA_DBS`) |
| `deploy-{dev,staging,prod}` | deploy | tier branch | `bash bin/deploy.sh $CI_COMMIT_REF_SLUG`; environments `dev`, `staging`, `production`; prod is `manual` unless `UBIX_PROD_DEPLOY_WHEN=on_success` (expressed as two `rules:` entries, since `when:` cannot take a variable) |
| `promote-to-staging`, `promote-to-prod` (+ `-destructive`) | promote | dev / staging | `bash bin/promote.sh <branch>`, the target being `UBIX_STAGING_BRANCH` / `UBIX_PROD_BRANCH`; a host with no staging tier defines its own promote |
| `require-approval-mr` | approval | MR | `php bin/ubix ci:requireApproval` |
| `ai-review-mr` | test | MR, allow_failure | skips cleanly without keys |
| `notify-failure-*` | notify | `on_failure` | `bin/lib/notify.sh` |
| `.ubix_maintenance` | maintenance | schedule + `MAINTENANCE_TASK` | hidden base only; the host defines concrete tasks (`purge-*`), see `ci/tests/consumer.gitlab-ci.yml` |

All hidden bases (`.ubix_build`, `.ubix_static_checks`, `.ubix_phpunit`, `.ubix_js_lint`,
`.ubix_go_checks`, `.ubix_migrate`, `.ubix_drift`, `.ubix_deploy`, `.ubix_promote`,
`.ubix_notify_failure`, `.ubix_maintenance`, …) and the tier selectors `.ubix_on_dev`,
`.ubix_on_staging`, `.ubix_on_prod`, `.ubix_on_mr` (rules plus the `UBIX_TIER` variables the
scripts read) are public API: a host `extends:` them to add its own jobs, e.g.
`extends: [.ubix_static_checks, .ubix_on_dev]`.

### 3.4 Overridable variables

Declared under `variables:` in the template with defaults; hosts set them in their own
`variables:` block (project/GitLab variables win over both).

| Variable | Default | Purpose |
|---|---|---|
| `UBIX_BUILD_TAG` | `shell` | runner tag for image jobs |
| `UBIX_DEPLOY_TAG` | `shell` | runner tag for kubectl jobs |
| `UBIX_DEV_BRANCH` / `UBIX_STAGING_BRANCH` / `UBIX_PROD_BRANCH` | `dev` / `staging` / `main` | tier branches (`master` also accepted for prod) |
| `UBIX_TIERS` | `dev,staging,prod` | tiers that get jobs (a host may drop staging) |
| `UBIX_RUNTIME_DOCKERFILE_DEV/STAGING/PROD` | `Dockerfile` / `Dockerfile_Staging` / `Dockerfile_Main` | per-tier Dockerfile |
| `UBIX_TEST_DOCKERFILE` | `Dockerfile_Test` | test image |
| `UBIX_NODE_DOCKERFILE` / `UBIX_GO_DOCKERFILE` | `Dockerfile_Node` / `Dockerfile_Go` | per-app images, parameterised by `APP_DIR` (added by the implementation) |
| `UBIX_PHPSTAN_PATHS` | `app bin php public tests` | paths phpstan analyses (added) |
| `UBIX_JS_TEST_SKIP` | empty | `app/*Js` names whose `npm test` cannot run in the plain node image (added) |
| `UBIX_AI_REVIEW_GUIDE` | `bin/ci/ai-review-guide.md` | host rules for `ci:aiReview`; ignored if absent (added) |
| `UBIX_BUILD_PLATFORMS` | `linux/amd64,linux/arm64` | buildx platforms (MR build forced to amd64) |
| `UBIX_CACHE_IMAGE` | empty | registry cache target; empty = none |
| `UBIX_VAULT_PREFIX` | `$CI_PROJECT_NAME` | `secret/<prefix>/…` paths for `db-<tier>`, `test-db`, `composer`; exported to the host's `vault_kv` as `VAULT_KV_PREFIX` |
| `UBIX_MIGRATE_ENABLED` | `true` | `false` removes migrate jobs (no DB) |
| `UBIX_DRIFT_ENABLED` | `false` | drift replay |
| `UBIX_PROD_DEPLOY_WHEN` | `manual` | `manual` or `on_success` |
| `UBIX_APP_DB_HOST` | empty = `%` on dev, `10.50.%` on staging/prod | grants host pattern; one value for every tier when set |
| `UBIX_APPROVAL_OWNERS` | empty (required if approval used) | usernames whose Approve/👍 counts |
| `UBIX_STAGING_VAULT` | `prod` | which uBixVault (and CI token) the staging branch reads: `prod` or `dev` (added 0.47.0) |
| `UBIX_CORE_PROJECT` | `ubixsys/ubixcore` | where `ci/bin` is fetched from (added 0.47.0) |
| `UBIX_CORE_REF` | empty = the `ubixsys/ubixcore` version in `composer.lock` | the `ci/bin` version (added 0.47.0) |
| `UBIX_NOTIFY_SCRIPT` | empty = uBixCore's `ci/bin/lib/notify.sh` | a path in the app to use its own helper instead (default changed in 0.47.0) |
| `UBIX_DEPLOY_SCRIPT` / `UBIX_PROMOTE_SCRIPT` | empty = uBixCore's `ci/bin/deploy.sh` / `ci/bin/promote.sh` | a path in the app to use its own instead (default changed in 0.47.0) |

### 3.5 Overriding

- **Change a variable:** set it in the host's `variables:`.
- **Replace/extend a job:** redefine the job by name in the host file; GitLab merges the
  keys (`script:` replaces, `variables:` merges).
- **Add a job:** `extends: .ubix_phpunit` etc.
- **Drop a job:** redefine it with `rules: [{ when: never }]`.
- **Change when a job runs:** `rules:` replaces the template's rules wholesale; copy the
  conditions you still want from the `.ubix_if` block at the top of the template (YAML anchors
  do not cross `include:`, so they must be copied as text).
- **Reorder stages:** a host that needs an extra stage redeclares `stages:` in full
  (stage lists do not merge).

### 3.6 What an app must provide

1. `Dockerfile` (+ `Dockerfile_Staging`, `Dockerfile_Main`, `Dockerfile_Test`; names via variables).
2. `app/<App>/<tier>-deploy.yaml`, and optionally `-service.yaml`, `-ingress.yaml`,
   `-cronjob*.yaml`, where `<tier>` is the branch slug (`dev`, `staging`, `main`).
3. Nothing else for scripts. Since 0.47.0 the deploy, promote and notify scripts are
   uBixCore's (`ci/bin/`), fetched at the version in the app's `composer.lock` with the job
   token (so the app must be on ubixcore's job-token allowlist, which Composer already
   needs). An app that must differ points `UBIX_*_SCRIPT` at its own copy; the aim is that
   none does. See §3.8.
4. A uBixVault tree under `secret/<UBIX_VAULT_PREFIX>/` (`db-dev|staging|prod`, `test-db`,
   `composer`) and the read-only CI tokens as masked GitLab variables.
5. `sql/grants/app.sql` if a dedicated DDL account is used.
6. Runner(s) with the configured tags, buildx builder, kubeconfigs.
7. Branch protection: `dev`, `staging`, `main` protected; `main` MR-only; protected
   environment `production` for who may press the prod deploy.

### 3.8 Shared scripts (`ci/bin`, 0.47.0)

uBixCore is the monorepo's architecture, so tooling every app needs lives here, not in each
app. Before 0.47.0 each app carried `bin/deploy.sh`, `bin/promote.sh` and `bin/lib/notify.sh`
(seeded from the skeleton), and the copies drifted: kitg's promote gained fail-closed checks,
Kelpie's deploy gained a rollout wait, and the skeleton's `vault_kv` ignored the prefix the
template exports. `ci/bin/` now holds one version of each, combining the fixes:

| Script | What it does |
|---|---|
| `lib/notify.sh` | `vault_for_branch` (prod branch → prod Vault; staging → `UBIX_STAGING_VAULT`; else dev), `vault_kv <path> <key>` under `secret/<VAULT_KV_PREFIX>/`, `discord <channel> <color> <title> <text>` |
| `deploy.sh <slug>` | applies each `app/<App>/<slug>-{deploy,service,ingress,cronjob*}.yaml` on every `UBIX_KUBECONFIGS` cluster, then waits for each Deployment's rollout (`UBIX_ROLLOUT_TIMEOUT`, default 300s; `0` skips) |
| `promote.sh <branch>` | fast-forward only; halts on a `Destructive:` migration unless acknowledged; refuses when it cannot resolve the target or diff the migrations |
| `vault-set-key.sh` | for people, not CI: sets one key in a KV v2 secret by read-merge-write with check-and-set, the value typed at a prompt or piped from a file, never in argv. In an app: `vendor/ubixsys/ubixcore/ci/bin/vault-set-key.sh` |

The template's hidden `.ubix_ci_fn` defines `ubix_ci_scripts`, which shallow-clones
`UBIX_CORE_PROJECT` at the locked version into `$CI_PROJECT_DIR.tmp/ubix-ci` (outside the
checkout, so a `docker build .` never copies it). It is fetched rather than read from the
image because the build job needs `vault_kv` before any image exists. Tests:
`ci/tests/scripts-test.sh` (offline, fake `kubectl`), run by uBixCore's `ci-template` job.

### 3.7 Branch and pipeline model

Unchanged from the working pipelines: `workflow:rules` creates pipelines for MR events,
schedules (maintenance only) and pushes to the tier branches; feature-branch pushes produce
none. Dev → staging → main moves only by the promote jobs (fast-forward), never by the
merge button on staging.

### 3.8 Extensions (opt-in, not core)

**Not in the first release** (`ci/extensions/` is not shipped yet; it graduates host gates once a
second host wants them). The intended shape: separately includable files, each with its own variables: Lighthouse,
DAST, gitleaks, cspell/knip, MR-size and owned-paths notices, emergency deploy and rollback,
smoke test. Neptune's own gates stay in neptune until two hosts want them.

## 4. Migration of existing hosts

1. Land the template and a tag (§6, step 0).
2. New hosts get it from the skeleton (step 3).
3. kitg: replace its file by the include plus ~30 lines of overrides (Discord notify,
   `MIGRATE` tier host, go/js apps are discovered, maintenance task). Verify parity on a
   branch pipeline before merging.
4. neptune last: its ~100 jobs mostly become extension includes or stay local; only the
   core spine moves.

## 5. Open questions and risks

- **Cross-instance include.** neptune lives on `git.lan.vsmedia.net`, the framework on
  `gitlab.brainchurts.com`; `include: project:` does not cross instances. Options: `remote:`
  URL to a raw tagged file (needs public read), or mirror `ci/` into the host.
- **Neptune-only gates** (Lighthouse gating promote, smoke-prod, DAST) are tied to its
  decision records; deciding what graduates to core is the owner's call.
- **Runner split** is a real operational difference; the template makes it configurable but
  does not provision it.
- **Rules migration risk.** kitg and neptune use `only:/except:`; moving to `rules:`
  changes edge behaviour (schedules, MR source). Needs a dry run per host.
- **Prod migrate/deploy ordering:** kitg makes the prod migrate manual and deploy automatic;
  neptune the opposite. The template defaults to neptune's (verify gates, deploy manual);
  kitg overrides `UBIX_PROD_DEPLOY_WHEN=on_success`.
- **Template testing.** Implemented as `ci/tests/lint.sh`: yamllint, `glab ci lint` on the
  template and on an example consumer, and `ci/tests/rules.py`, which dry-runs the template
  through GitLab's lint API per tier/variable scenario and checks the job set and `when:`.
  Still open: running it from the framework's own `.gitlab-ci.yml` (that file is outside the
  implementing lane's scope), and exercising the scripts against a real host (kitg is the
  parity candidate, §4).
- **Coverage.** This analysis read the two named repos only; ubixshepherd, ubixvault and
  ubixkelpie pipelines were not reviewed.

## 6. Rollout

Each step is one merge request's worth, in order. Step 0 is a prerequisite and **outside
this lane's scope** (it touches `ci/`), so it needs its own lane.

0. **Implement the template** — **done** (item 103): `ci/ubixcore-pipeline.yml`, `ci/tests/`.
   Still to do: `ci/extensions/*`, a lint job in the framework pipeline, and the release tag
   (the owner's). Source of truth for steps 1–3.
1. **Docs page on ubixsys-web** — public reference for the pipeline jobs.
   - Page: `site/src/pages/docs/ci-pipeline.astro` → `/docs/ci-pipeline` (the site has only
     marketing pages today, so this also creates the first `docs/` section; link it from the
     header and from `/ubixcore`).
   - Outline: (1) overview and stage diagram; (2) quick start: the `include:` snippet and
     version pinning; (3) job reference table (name, stage, rules, needs, key variables);
     (4) variables reference (§3.4); (5) what your app provides (§3.6); (6) migrations:
     verify, apply, destructive, drift; (7) promotion and prod deploy flow; (8) override
     cookbook (§3.5); (9) extensions; (10) troubleshooting (host cross-check refusal, missing
     vault creds, held destructive migration).
2. **Docs in every uBixCore repo** — a short `docs/ci.md` (or a "CI" README section) per
   repo: link to the web page and the pinned template ref, the repo's overrides, and the
   vault prefix. One MR per repo (they live in separate repositories), or batched by the
   owner. Repos found:
   - `kitg` (`kitg/kitg`) — depends on `ubixsys/ubixcore`
   - `ubixkelpie` (`ubixsys/ubixkelpie`) — depends on `ubixsys/ubixcore ^0.45`
   - `project-neptune` (`vsm-labs/project-neptune`) — descendant, no composer dependency;
     different GitLab instance (see §5); confirm it should be included
   - `ubixcore` (`ubixsys/ubixcore`) — the framework's own pipeline; note which jobs it
     shares and why it differs (publish jobs)
   - `ubixsys/ubixcore-skeleton` — covered by step 3
   - `ubixshepherd`, `ubixvault` (`ubixsys`) — have pipelines; **not confirmed** to be uBixCore
     apps, confirm before adding
3. **Sample in ubixcore-skeleton** — replace `skeleton/.gitlab-ci.yml` with an `include:`
   plus a commented overrides block (~20 lines), and update `skeleton/docs/ci-setup.md`
   stages section to point at the template and the web page. Published by the existing tag
   pipeline.
