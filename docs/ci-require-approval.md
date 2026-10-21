# The `require-approval` include

One includable CI file that puts the merge sign-off gate (an Approve or a 👍 from the owner;
standard: [`standards/branching-and-git-workflow.md`](standards/branching-and-git-workflow.md)
§ Merge sign-off) into any repo, whatever it is built with. It needs no Docker, no PHP and no
other uBixCore piece.

Not for repos that already include `ci/ubixcore-pipeline.yml`: its `require-approval-mr` is
the same gate (set `UBIX_APPROVAL_OWNERS` there). Do not include both.

## Add it to a repo

```yaml
include:
  - project: ubixsys/ubixcore
    ref: v0.47.0          # a v* release tag that ships ci/require-approval.yml
    file: ci/require-approval.yml

variables:
  UBIX_APPROVAL_OWNERS: "cwolsen"
```

That is all the YAML. It works with any `stages:` list (or none): the job runs in `.post`,
which every pipeline has, after everything else. The script is inlined in the file, so there is
nothing to fetch and no job-token allowlist entry to add.

If the repo's pipeline only runs on branches, or only on merge requests, it still works: the
job runs on merge request pipelines and on branch pipelines of any non-default branch. A
repo with *no* jobs that run on such pipelines needs a `workflow:rules` that lets them through.

## Variables

| Variable | Default | Meaning |
| --- | --- | --- |
| `UBIX_APPROVAL_OWNERS` | none, **required** | Usernames (comma-separated) whose own Approve/👍 counts on their own MR. Empty fails the job with a message. `MERGE_APPROVAL_OWNERS` is accepted too. |
| `UBIX_APPROVAL_BRANCH` | the default branch | The branch whose MRs are guarded. An MR into any other branch passes with a note. |
| `UBIX_APPROVAL_ARMS_MERGE` | `false` | `true`: a verified sign-off arms merge-when-pipeline-succeeds, pinned to the signed-off commit. Needs `APPROVAL_MERGE_TOKEN`. |
| `UBIX_APPROVAL_IMAGE` | `alpine:3` | Job image. Shell runners ignore it. |

CI/CD variables (Settings → CI/CD, masked, **not** protected, because MR branches are unprotected):

- `APPROVAL_GITLAB_TOKEN` (or `AI_REVIEW_GITLAB_TOKEN`): a project or group access token,
  Reporter, `read_api`. Without it the job passes with an `UNVERIFIED` line instead of blocking.
- `APPROVAL_MERGE_TOKEN`: only for `UBIX_APPROVAL_ARMS_MERGE`; Developer, `api`.

Then turn **Pipelines must succeed** on, or the job is only a red mark.

## No Docker, or a different runner

The job is a plain script on a plain image, so it needs only `bash`, `curl` and `jq`. They are
installed with `apk` or `apt-get` when the image lacks them.

- **Shell runner** (the `image:` is ignored; the host needs bash, curl, jq): override the tags.
- **Tagged runners**, or a runner that cannot pull `alpine:3`: override in the repo's own file.

```yaml
require-approval:
  tags: [shell]
  # or: variables: { UBIX_APPROVAL_IMAGE: registry.example.com/mirror/alpine:3 }
```

Overriding a key in the repo's own job merges with the included one; everything else stays.

## What it checks

Port of `bin/ubix ci:requireApproval`. Passes when the MR has an Approve or a 👍 from someone
other than its author, or from its author when listed in the owners (the log says
**SELF SIGN-OFF**). On a branch pipeline it finds the MR by source branch; no open MR fails.
Fails open (`UNVERIFIED`, passes) with no token or when GitLab is unreachable. GitLab does not
re-run a pipeline when someone approves: approve, then retry the job (or let the uBixOps
webhook do it). Agents never approve or 👍.

## Rolling it out to a repo (the per-repo merge request)

1. Add the `include:` and `UBIX_APPROVAL_OWNERS` above to `.gitlab-ci.yml` (create a minimal
   file with just those, if the repo has none).
2. Someone with settings rights creates `APPROVAL_GITLAB_TOKEN` and turns on *Pipelines must
   succeed* (a setting write, so the person's call). Create the token first: until it exists the
   job passes as `UNVERIFIED` and gates nothing.
3. Open the MR, wait for the owner's sign-off. The first pipeline fails on this MR until it is
   approved, which is the gate working.

## Maintaining it

`ci/require-approval.yml` carries a generated copy of `ci/bin/require-approval.sh`. Edit the
script, run `ci/bin/inline-require-approval.sh`, and commit both; `ci/tests/scripts-test.sh`
fails when they differ and tests every verdict offline against a fake `curl`.
