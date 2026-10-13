# uBixOps — the framework's operations app

**Version:** 1.1
**Date:** 2026-10-11
**Status:** Active

uBixOps is an HTTP app that ships **inside** uBixCore, for operational plumbing that
belongs to no product. Its first job: when someone approves or un-approves a merge
request, re-run that MR's sign-off job (`ci:requireApproval`), because GitLab does not
re-run a pipeline on approval.

## 1. What ships, and how it is inherited

| Piece | Where |
|---|---|
| The app: routes, wiring, middleware | `php/Ubix/Bootstrap/apps/UbixOpsApi/src/` |
| `Ubix\Controller\Ops\OpsController` — `GET /health`, `POST /gitlab/merge-request-hook` | framework |
| `Ubix\Service\Ci\ApprovalWebhookService` — verify, decide, retry one job | framework |
| Image `ghcr.io/ubixsys/ubixcore-ops:<version>` | `Dockerfile_Ops`, published on every `v*` tag by `.github/workflows/ops-image.yml` |
| Helm chart `ubix-ops` | `deploy/charts/ubix-ops/` |

`Ubix\Bootstrap\http()` serves `app/<AppName>` from the host when it exists, and
otherwise an app the framework ships under `apps/<AppName>`. So **any image built on
uBixCore can run uBixOps with `APP_NAME=UbixOpsApi` and no code of its own**; a host that
wants to change it copies the folder into its own `app/` and the copy wins.

## 2. What a delivery does

1. Wrong or missing `X-Gitlab-Token` → **401**. Nothing is sent to GitLab.
2. Anything but a merge-request `approved`/`unapproved` event, or a `thumbsup` emoji
   awarded or revoked on a merge request (§4) → 200, ignored.
3. No token for the project (neither its own entry nor the fallback, §3) → 200, ignored.
4. Otherwise: read the MR's head pipeline and find the **newest** job named in
   `APPROVAL_JOB_NAMES`, then act on its state:

   | Job state | Action |
   |---|---|
   | `created`, `pending`, `preparing`, `running`, `waiting_for_resource` | left alone |
   | `manual` (never run) | `POST /jobs/:id/play` |
   | `success`, and the event added a sign-off | left alone: it already passed |
   | anything else, including `success` after an un-approve or a revoked thumbs-up | `POST /jobs/:id/retry` |

   A passed job is still retried when a sign-off is withdrawn, so the verdict can turn
   red again. **One job, never a new pipeline**: a pipeline would re-run every check,
   including the AI review, for each click.
5. Any GitLab failure → **200**, with the reason logged. GitLab disables a webhook that
   keeps failing, and a disabled webhook fails silently, so only a bad secret is an error.

## 3. Deploying it

```sh
helm install ubix-ops deploy/charts/ubix-ops -n ubix-ops --create-namespace \
  --set gitlab.apiUrl=https://gitlab.example.com/api/v4 \
  --set existingSecret=ubix-ops-credentials
```

The Secret carries `GITLAB_WEBHOOK_SECRET` plus tokens, in either or both forms:

- `GITLAB_API_TOKEN` — **one token for any project.** Used for every project with no entry
  in `GITLAB_PROJECT_TOKENS`, so a new repo needs only the webhook. Make it a **group
  access token** on the group that holds your repos (on ours, `ubixsys`).
- `GITLAB_PROJECT_TOKENS` — `projectId=token,projectId=token`, for a project that needs
  its own token. A project's entry wins over `GITLAB_API_TOKEN`.

Every token is **Developer role, `api` scope**: retrying or playing a job needs
Developer; the Reporter token that `ci:requireApproval` reads with is not enough. A
token only reaches projects inside its own group, so a repo in another group needs its
own entry (or another uBixOps).

Which projects have a token is recorded in the cluster repo's `kubernetes/ubix-ops`
README (at the time of writing: kitg and ubixcore; Kelpie and the rest not yet). With
`GITLAB_API_TOKEN` set, a project in the group needs only the webhook. With uBixVault, skip the Secret and pass
`VAULT_ADDR`, `VAULT_K8S_ROLE` and `VAULT_APP_KV_PATH` through `extraEnv`; uBixCore
resolves them at boot.

Then, per GitLab project: **Settings → Webhooks → Add new webhook**, URL
`https://<host>/gitlab/merge-request-hook`, the same secret token, trigger **Merge request
events**, and **Emoji events** if thumbs-up sign-offs should re-run the job (§4). Keep the endpoint
internal where you can: GitLab must reach it, nobody else needs to.

## 4. Thumbs-up

`ci:requireApproval` counts a `thumbsup` award as a sign-off, but the merge-request hook
does not fire on award emoji. GitLab has a separate **Emoji events** webhook
(`object_kind: emoji`, with a top-level `event_type` of `award` or `revoke`, and the
merge request under `merge_request`), and uBixOps handles it: a `thumbsup` awarded to a
merge request is treated like an approval, and one revoked like an un-approval. Any other
emoji, or an emoji on an issue or commit, is ignored.

- Turn on **Emoji events** on the webhook, besides Merge request events.
- It is a *project* webhook trigger (`emoji_events` in the hooks API). Our GitLab
  (19.4.1-ee) supports it, but it is off on a hook that existed before: the uBixOps hook
  on ubixcore had `emoji_events: false` on 2026-10-11, so turning it on is a manual step.
- The log tells the two apart. The outcome reads `retried: job N (require-approval) for !M
  after approved` for the Approve button, and `... after thumbsup award` for a thumbs-up
  (`after unapproved` and `after thumbsup revoke` for the removals).
- The payload shape was verified against real GitLab 19.4.2 deliveries: `object_kind:
  emoji`, the verb as top-level `event_type` (`award` or `revoke`), and no
  `object_attributes.action`. `object_attributes.name` is the emoji (`thumbsup`),
  `object_attributes.awardable_type` is `MergeRequest`, and the merge request is under
  `merge_request.iid`; a 👍 on a comment is `awardable_type: Note` and is ignored. The
  handler reads `event_type` and falls back to `object_attributes.action`. (Through
  v0.46.0 it read only the latter, so no 👍 was ever recognised; fixed in v0.46.1.) It
  dispatches on `object_kind`, not the header, so both hooks share the one endpoint.

## 5. Why it is a framework app and not a product route

The first draft hung the webhook off a product API. Three reasons it moved:

- It serves every project on the GitLab instance — the framework's own included — so it
  cannot belong to any one of them.
- It holds Developer tokens for other repositories; a product's namespace, with its own
  payments and personal data, is the wrong place for that blast radius.
- Shipping it as image + chart means another organisation running uBixCore deploys it the
  same way we do, with no fork.
