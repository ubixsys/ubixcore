# ci/

The shared uBixCore GitLab CI template. Spec and rationale: `docs/design/ci-pipeline-template.md`.

- `ubixcore-pipeline.yml` — the template. Hosts include it pinned to a `v*` release tag:

  ```yaml
  include:
    - project: ubixsys/ubixcore
      ref: v0.46.0
      file: ci/ubixcore-pipeline.yml
  ```

  Tune it with the `UBIX_*` variables (declared with defaults at the top of the file); extend it
  with the hidden `.ubix_*` jobs; drop a job with `rules: [{ when: never }]`.
- `tests/consumer.gitlab-ci.yml` — an example consumer showing each override style.
- `tests/lint.sh` — yamllint, `glab ci lint`, and `tests/rules.py` (per-scenario dry runs of the
  rules through GitLab's lint API). Run it before every change to the template.
