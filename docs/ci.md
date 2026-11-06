# CI pipeline

This repo **is the template source, not a consumer**. Its `.gitlab-ci.yml` does not
include `ci/ubixcore-pipeline.yml` — that file exists here so *host* repos can include
it. Host consumption is a pinned include plus `UBIX_*` variables (see
[How other repos consume it](#how-other-repos-consume-it) below).

Design and rationale for the shared template: `design/ci-pipeline-template.md`.
The merge sign-off gate: `ci-require-approval.md` and
`standards/branching-and-git-workflow.md` § Merge sign-off.

## What `.gitlab-ci.yml` includes

Exactly one include, and it is not the framework template:

```yaml
include:
  - project: ubixsys/ubixkelpie
    ref: 788c9416214a6bdf4ceaffd851eaf34ac7850a38
    file: ci/kelpie.yml
```

It provides the `kelpie-review` job (stage `test`): Kelpie's advisory AI review on merge
requests. The ref is pinned to a commit, not a branch, so the review job cannot change
this CI unreviewed; bump it on purpose. The job reviews only MRs into
`AI_REVIEW_BASE_BRANCH` (`main`), needs the group variables `OPENROUTER_API_KEY`,
`AI_REVIEW_GITLAB_TOKEN`, `KELPIE_REGISTRY_USER` / `KELPIE_REGISTRY_TOKEN`, and until
they exist it exits 75, is allowed to fail, and the pipeline stays green.

## Stages and jobs

`stages: test → lint-and-test → publish → notify → approval`.

Shared plumbing: `default.retry` (max 2 on runner/API/scheduler failures) and
`.on_any_ref` (every branch **and** tag gets the gate; the `publish` jobs additionally
gate themselves with `only: [tags]`).

| Stage | Job | What it does |
|---|---|---|
| `test` | `build-test-image` | Resolves `Dockerfile_Test`'s `BASE_IMAGE` to a digest once per build, then builds and pushes `:<sha>-test` (amd64). Retries `script_failure` too (registry blips during push). |
| `test` | `build-ops-image` | Branches only: builds `Dockerfile_Ops`, pushes nothing. The uBixOps image itself is published from GitHub on a tag, so this is the only pre-release check of that Dockerfile. |
| `test` | `kelpie-review` | From the include above; advisory only. |
| `lint-and-test` | `static-checks` | `phpcs` + `phpstan` (level max) inside the test image. |
| `lint-and-test` | `ci-template` | Shellcheck, the offline `ci/bin` tests (`scripts-test.sh`), and yamllint — in a throwaway `alpine:3.22` container, `needs: []` so it starts at once. Guards the shared template below. |
| `lint-and-test` | `phpunit` | Vault credentials from `secret/ubixcore/test-db`, then `database:resetSchema test`, `CREATE DATABASE SYSTEMS`, `migrate:up --target=test`, `vendor/bin/phpunit`. The fixture schema is `sql/ubixcore_test.sql`. |
| `publish` | `publish-composer` | Tags only: publishes `ubixsys/ubixcore` to this project's Composer registry via the job token. |
| `publish` | `publish-skeleton` | Tags only, after `publish-composer`: subtree-splits `skeleton/` as `ubixsys/ubixcore-skeleton` (`bin/publish-skeleton.sh`; needs `GITLAB_SKELETON_TOKEN`, `allow_failure` without it). |
| `publish` | `bump-skeleton-ref` | Tags only, after `publish-skeleton`: opens an MR pinning the skeleton's template `ref:` (and the snippets in `ci-setup.md` and `ci/README.md`) to the new tag. Needs `BUMP_GITLAB_TOKEN`; never tags, so it cannot retrigger itself. |
| `publish` | `publish-npm` | Tags only, after `publish-composer`: publishes `@ubixsys/ubixcore` (version = the tag) to npmjs.com when `secret/ubixcore/npmjs` holds an automation token, else to this project's GitLab npm registry. (Composer packages reach Packagist through its GitHub hook — nothing to do here.) |
| `notify` | `notify-failure` | `when: on_failure`: posts to `#ubixcore-alerts` via `bin/lib/notify.sh`, which reads the Discord webhook from uBixVault with `UBIXVAULT_CI_TOKEN_DEV` / `UBIXVAULT_CI_TOKEN_PROD`. |
| `approval` | `require-approval` | Non-default branch pipelines only: `php bin/ubix ci:requireApproval` in the test image. Fails until the branch's MR has an Approve or 👍 (`MERGE_APPROVAL_OWNERS: cwolsen`); with *Pipelines must succeed* on, that blocks the merge. Its own stage **after** `notify` on purpose, so unapproved pushes don't page `#ubixcore-alerts`. Tokens: `APPROVAL_GITLAB_TOKEN` or `AI_REVIEW_GITLAB_TOKEN`. GitLab does not re-run a pipeline on approval — approve, then retry this job. |

## Per-repo overrides and extra jobs

There are none: the whole pipeline lives in `.gitlab-ci.yml` (281 lines) with no local
overrides file and no extra jobs beyond the table above.

## No deploy, no promote — on purpose

uBixCore is a framework, not a service: there is no runtime image, deploy or promote
here. Releases install it into host projects from the package registries. The root
`Dockerfile*` and `bin/{deploy,promote}.sh` are the templates hosts inherit through
`skeleton/`; ubixcore itself never runs them.

## How other repos consume it

Host repos include the shared template **pinned to a `v*` release tag** (never `main`):

```yaml
include:
  - project: ubixsys/ubixcore
    ref: v0.47.0
    file: ci/ubixcore-pipeline.yml
```

- `ci/ubixcore-pipeline.yml` — `build → test → migrate → deploy → promote → notify`
  (+ `approval`, `maintenance`). Everything a host tunes is a `UBIX_*` variable
  (defaults at the top of the file: runner tags, tier branches, Dockerfiles,
  `UBIX_TIERS`, `UBIX_MIGRATE_ENABLED`, `UBIX_PROD_DEPLOY_WHEN`, `UBIX_APPROVAL_OWNERS`,
  …). Hidden `.ubix_*` jobs are public API: `extends:` them to add a job, redefine a
  concrete job to change it, or drop one with `rules: [{ when: never }]`. The
  `ci/bin/` scripts (deploy, promote, notify) run at the uBixCore version in the app's
  `composer.lock`; `UBIX_*_SCRIPT` variables point at an app's own script instead.
  The skeleton's `docs/ci-setup.md` is the operator checklist for a host's first
  green pipeline. Full contract: `ci/README.md` and `design/ci-pipeline-template.md`.
- `ci/require-approval.yml` — the merge sign-off gate as a standalone include for
  repos that do not use the full template (needs no Docker, no PHP). Usage:
  `ci-require-approval.md`. Repos on the full template already have it as
  `require-approval-mr`; do not include both.

Other uBixCore repos needing the same per-repo doc: every host repo generated from
the skeleton (each gets its own `docs/ci.md` pointing at the template with its
overrides), plus `ubixvault`, `ubixkelpie`, and any repo carrying its own
`.gitlab-ci.yml` — the standalone-`require-approval.yml` consumers document just
that include.
