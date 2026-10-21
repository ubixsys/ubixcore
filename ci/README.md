# ci/

The shared uBixCore GitLab CI template. Spec and rationale: `docs/design/ci-pipeline-template.md`.

- `ubixcore-pipeline.yml` — the template. Hosts include it pinned to a `v*` release tag:

  ```yaml
  include:
    - project: ubixsys/ubixcore
      ref: v0.47.0
      file: ci/ubixcore-pipeline.yml
  ```

  Tune it with the `UBIX_*` variables (declared with defaults at the top of the file); extend it
  with the hidden `.ubix_*` jobs; drop a job with `rules: [{ when: never }]`.
- `require-approval.yml` — the merge sign-off gate as a standalone include (no Docker, no PHP,
  no other template pieces), for repos that do not use the full template. Its script is
  `bin/require-approval.sh`, inlined by `bin/inline-require-approval.sh`. How to use it:
  `docs/ci-require-approval.md`.
- `bin/` — the scripts every app's pipeline runs, so apps keep no copies (since 0.47.0):
  `lib/notify.sh` (`vault_kv`, `discord`), `deploy.sh`, `promote.sh`, and `vault-set-key.sh`
  for people writing secrets. The template fetches them at the uBixCore version in the app's
  `composer.lock`. Design: `docs/design/ci-pipeline-template.md` §3.8.
- `bin/bump-skeleton-ref.sh` — run by ubixcore's own tag pipeline (`bump-skeleton-ref` job): opens an MR
  that pins the skeleton's `ref:` (and the snippets in `ci-setup.md` and here) to the new tag.
  Needs the `BUMP_GITLAB_TOKEN` project access token.
- `tests/scripts-test.sh` — offline tests for `bin/` (fake `kubectl`, no network).
- `tests/consumer.gitlab-ci.yml` — an example consumer showing each override style.
- `tests/lint.sh` — yamllint, `glab ci lint`, and `tests/rules.py` (per-scenario dry runs of the
  rules through GitLab's lint API). Run it before every change to the template.
