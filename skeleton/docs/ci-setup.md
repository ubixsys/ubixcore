# CI setup for a uBixCore host project

Everything the pipeline in `.gitlab-ci.yml` needs before it goes green, in the
order to create it. Secrets live in **uBixVault**; GitLab holds only the
read-only tokens that let jobs fetch them. Nothing here is committed.

The pipeline is **not** written in this project: `.gitlab-ci.yml` includes uBixCore's
shared template and keeps only this project's overrides (section 0).

## 0. The include, the jobs it brings, and overriding them

```yaml
include:
  - project: ubixsys/ubixcore
    ref: v0.47.0
    file: ci/ubixcore-pipeline.yml
```

Pin `ref` to a uBixCore release tag, never `main`. Keep it in step with the
`ubixsys/ubixcore` version in `composer.lock`: the template also fetches the
deploy, promote and notify scripts (`ci/bin`) from uBixCore at that locked
version, so apps keep no copies. The `ref:` line is marked `ubixcore-ref` so a
tool can find it. Upgrading is `composer update ubixsys/ubixcore`, bump `ref`,
commit both.

Stages and the jobs the template brings (`<tier>` is `dev`, `staging`, `prod`):

| Stage | Jobs |
|---|---|
| `build` | `build-<tier>`, `build-mr` (runtime image, test image, optional node/go images) |
| `test` | `static-checks-*` (phpcs, phpstan), `phpunit-*`, `js-lint-*`, `go-checks-*`, `ai-review-mr` |
| `migrate` | `migrate-verify-*`, `migrate-apply-*` (+ `-destructive` manual variants), optional `drift-*` |
| `deploy` | `deploy-<tier>` (applies `app/*/<tier>-{deploy,ingress,service}.yaml`; prod is manual by default) |
| `promote` | `promote-to-staging`, `promote-to-prod` (+ `-destructive`): fast-forward dev → staging → main |
| `notify` | `notify-failure-<tier>` (Discord) |
| `approval` | `require-approval-mr`: an owner's Approve or 👍 is required to merge |
| `maintenance` | scheduled `cron:<task>` jobs, which you define (below) |

Pipelines run for merge requests, schedules, and pushes to `dev`, `staging` and
`main`; a feature-branch push runs none.

Overriding, from least to most invasive:

1. **Variables.** Set a `UBIX_*` variable in this file's `variables:` (or in
   CI/CD settings; project variables win). The ones you will touch:
   `UBIX_APPROVAL_OWNERS` (required), `UBIX_TIERS`, `UBIX_MIGRATE_ENABLED`,
   `UBIX_PROD_DEPLOY_WHEN`, `UBIX_BUILD_TAG` / `UBIX_DEPLOY_TAG` (runner tags),
   `UBIX_*_DOCKERFILE_*`, `UBIX_PHPSTAN_PATHS`, `UBIX_VAULT_PREFIX`. All are
   declared with defaults at the top of the template.
2. **Change a job.** Redefine it by name; keys merge, `script` replaces:
   `phpunit-dev: { variables: { MY_FLAG: "1" } }`.
3. **Drop a job.** `ai-review-mr: { rules: [{ when: never }] }`.
4. **Add a job.** Extend a hidden base: `extends: [.ubix_static_checks, .ubix_on_dev]`.
   A maintenance task:
   `purge: { extends: .ubix_maintenance, rules: [{ if: '$CI_PIPELINE_SOURCE == "schedule" && $MAINTENANCE_TASK == "purge"' }] }`.
5. **Your own scripts.** Point `UBIX_DEPLOY_SCRIPT`, `UBIX_PROMOTE_SCRIPT` or
   `UBIX_NOTIFY_SCRIPT` at a path in this project instead of uBixCore's.

Full variable list and rationale: `docs/design/ci-pipeline-template.md` in uBixCore.

## 1. A runner

Any GitLab runner with the tag `shell` (or the tags set in `UBIX_BUILD_TAG` /
`UBIX_DEPLOY_TAG`), Docker with a buildx builder named `multiarch-builder`, and
kubeconfigs for your clusters at the paths uBixCore's `ci/bin/deploy.sh` expects (`/home/gitlab-runner/.kube/devstageconfig`, …). An
instance-wide shared runner serves every project with no per-project setup.

## 2. Access to uBixCore (the private Composer registry)

The runtime image runs `composer install`, which fetches `ubixsys/ubixcore` from
the `ubixsys` group Composer registry. Two options; the build job tries the
first and falls back to the second:

- **Deploy token in Vault (recommended):** on the `ubixsys` group create a
  deploy token with `read_package_registry`; store it as
  `secret/<project>/composer` with keys `username` and `token` (step 4 does this).
- **Job token allowlist:** on the `ubixsys/ubixcore` project, Settings → CI/CD
  → Job token permissions, add your project. Needed regardless of the registry:
  the template clones `ci/bin` from that project with the job token.
- **Base image:** the Dockerfiles default to the public
  `ghcr.io/ubixsys/ubixcore-php:8.5`; nothing to configure. To build from a
  private mirror instead, pass `--build-arg BASE_IMAGE=<registry>/<image>:<tag>`
  in the build job.

Developers also need the token locally, once:
`composer config -g gitlab-token.<gitlab host> <username> <token>`.

## 3. GitLab CI/CD variables (Settings → CI/CD → Variables, all Masked, not Protected)

| Variable | What | Where it comes from |
|---|---|---|
| `UBIXVAULT_CI_TOKEN_DEV` | read-only Vault token for the dev branch | printed by `bin/vault-ci-setup.sh dev <project>` |
| `UBIXVAULT_CI_TOKEN_PROD` | same for staging/main | `bin/vault-ci-setup.sh prod <project>` |
| `GITLAB_PROMOTE_TOKEN` | lets the promote jobs push staging/main | project access token on **this** project: Maintainer, scopes `api` + `write_repository` |

`APPROVAL_GITLAB_TOKEN` (or `AI_REVIEW_GITLAB_TOKEN`; Reporter, `api`, masked,
not protected) lets `require-approval-mr` read approvals and 👍. Also set
`UBIX_APPROVAL_OWNERS` in `.gitlab-ci.yml`.

**Approval finishes the merge (optional).** Set `UBIX_APPROVAL_ARMS_MERGE: "true"` and
add `APPROVAL_MERGE_TOKEN` (a project or group access token: Developer, `api`, masked,
not protected). Once `require-approval-mr` has verified an owner's Approve or 👍 (not the
fail-open `UNVERIFIED` pass) it arms merge-when-pipeline-succeeds on that commit; a later
push voids it. Pair it with the uBixOps webhook, which re-runs the job on an approval, and
an Approve or 👍 merges the MR with no further step. GitLab's own gates (Pipelines must
succeed, resolved threads) still apply. Without the token the job only logs that it did
not arm. Keep the Reporter token above read-only; this one can merge.

`CI_REGISTRY_*` and `CI_JOB_TOKEN` are provided by GitLab.

## 4. uBixVault secrets (`secret/<project>/…`)

Run once per Vault with an admin token in the environment (never in a file):

```bash
export VAULT_TOKEN=<admin> VAULT_ADDR=https://vault.dev.example.com
export TEST_DB_HOST=… TEST_DB_PORT=3306 TEST_DB_USERNAME=… TEST_DB_PASSWORD=… TEST_DB_DATABASE=…
export COMPOSER_DEPLOY_USERNAME=… COMPOSER_DEPLOY_TOKEN=…
export DISCORD_OPS_WEBHOOK_URL=… DISCORD_ALERTS_WEBHOOK_URL=…
./bin/vault-ci-setup.sh dev <project>
```

It creates the policy `<project>-ci-ro` (read on `secret/<project>/*`), writes
`test-db`, `composer`, `discord`, and prints the CI token for step 3.

| Secret | Keys | Read by |
|---|---|---|
| `secret/<project>/test-db` | host, port, username, password, database | phpunit job (`database:resetSchema test`, `migrate:up`) |
| `secret/<project>/composer` | username, token | build job (Composer auth as a BuildKit secret) |
| `secret/<project>/discord` | ops_webhook_url, alerts_webhook_url, reviews_webhook_url | uBixCore `ci/bin/lib/notify.sh` |
| `secret/<project>/db-<tier>` (`dev`, `staging`, `prod`) | host, port, database, write_username, write_password; optional migration_username, migration_password (a dedicated DDL user, recommended) | migrate jobs (`migrate:status` + `migrate:up --target=<tier>` before each deploy) |
| `secret/<app>/<env>/db` | read_username, read_password, write_username, write_password | the running pods (`VAULT_ADDR` + `VAULT_K8S_ROLE`, see the app manifests) |

## 5. Kubernetes

- Image pull secret `regcred` in every target namespace (a deploy token with
  `read_registry`).
- A uBixVault Kubernetes-auth role bound to the namespace and ServiceAccount
  your pods use, named in `VAULT_K8S_ROLE` in `app/*/<env>-deploy.yaml`.
- Non-secret runtime config (`MEMCACHE_SERVERS`, `MYSQL_*_HOST/PORT/DATABASE`,
  `LOGGER_PATH`, `LATTE_*`, `LOG_LEVEL`, `IS_*`) as `env` on the Deployment.
  **Do not bake a `.env` into the image** — the image ships none on purpose.

## 6. First pipeline

Push to `dev`. Expected first-run failures, in order, if a step above was
skipped: Composer 401 (step 2), "no test-db credentials from vault" (steps 3–4),
deploy `kubectl` errors (step 1 kubeconfig, step 5 secrets).
