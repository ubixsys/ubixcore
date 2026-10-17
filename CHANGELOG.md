# Changelog

## 0.47.0 - 2026-10-17

### CI template

- New: `ci/ubixcore-pipeline.yml`, the shared GitLab CI pipeline for uBixCore apps (build,
  test, migrate, deploy, promote, notify, approval, maintenance), tuned with `UBIX_*`
  variables. Include it pinned to this tag. Design: `docs/design/ci-pipeline-template.md`.
- `ci/bin/`: the deploy, promote and notify scripts apps used to copy now ship with uBixCore,
  one version combining the copies' fixes: promote refuses when it cannot check the batch for
  destructive migrations, deploy also applies `-cronjob*.yaml` and waits for each rollout,
  `vault_kv` reads `secret/<prefix>/` (the skeleton's copy ignored the prefix). Also
  `ci/bin/vault-set-key.sh` (from kitg) for writing one secret key safely.
- The template runs those scripts by default, fetched at the uBixCore version in the app's
  `composer.lock`. Set `UBIX_NOTIFY_SCRIPT`, `UBIX_DEPLOY_SCRIPT` or `UBIX_PROMOTE_SCRIPT` to a
  path to use an app's own script instead.
- `UBIX_STAGING_VAULT` (`prod` default, or `dev`): which uBixVault the staging branch reads.
- uBixCore's own pipeline runs shellcheck, the script tests and yamllint (`ci-template`).

### Console

- Fix: `code:review` no longer crashes when PHPStan's result cache is missing or unreadable.

## 0.46.1 - 2026-10-13

### uBixOps

- Fix: a 👍 on a merge request is now recognised as a sign-off. GitLab 19.4.2 sends the
  emoji verb as top-level `event_type` (`award`/`revoke`), not `object_attributes.action`,
  so every emoji event was answered "not an approval event". `event_type` is read first,
  with `object_attributes.action` as the fallback.

## 0.46.0 - 2026-10-12

### uBixVault

- `VAULT_DB_STRATEGY=none` opts an app with no database out of the `MYSQL_*` credential
  read, so its app secrets (`VAULT_APP_KV_PATH(S)`) still hydrate. Unset, `kv` and
  `dynamic` behave exactly as before: a missing database secret still fails loudly.

### uBixOps

- A fallback token, `GITLAB_API_TOKEN` (chart value `apiToken`), is used for any project
  with no `GITLAB_PROJECT_TOKENS` entry, so a new repo needs only the webhook.
- A sign-off job that is `manual` and not yet run is played (`/jobs/:id/play`) instead of
  retried.
- An approval no longer retries a sign-off job that already passed; an un-approve still
  does, so the verdict can turn red.
- Emoji events: a `thumbsup` awarded to or revoked from a merge request re-runs the job.
- Chart `ubix-ops` 0.2.0.
