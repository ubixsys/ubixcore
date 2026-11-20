# Branching and Git Workflow

> **Status:** v0.14 — every merge needs a human sign-off since 2026-09-30 (see [Merge sign-off](#merge-sign-off-2026-09-30)); initial decisions resolved 2026-05-18; concurrent agent-session model added 2026-07-08 (see [Concurrent Agent Sessions](#concurrent-agent-sessions)); worktree bootstrap shipped as `ubix code:worktree`; `dev` is MR-only since 2026-07-30, with the machine gate's first pre-merge leg (`cspell-knip-mr`) added 2026-08-24 (see [One land path](#one-land-path-mr-only-dev-2026-07-30)); the path beyond `dev` is [Promotion to Production](#promotion-to-production-dev--staging--main--prod); remaining enforcement candidates tracked in [Future Automation](#future-automation-next-iteration). **v0.13 (2026-09-12): this document now describes two repository profiles** — see [Repository Profiles](#repository-profiles). uBixCore itself moved to the framework profile (trunk + tags) when it stopped deploying anything; hosts built from the skeleton keep the product profile unchanged.

This document defines how branches are created, kept in sync, and merged in uBix Core and in the
projects built on it — whether the work is done by a human or by an AI agent session.

## Repository Profiles

**Read this section first: it decides which of the rest applies to you.**

A branch-per-environment ladder is a *deployment* pattern. It earns its keep only when a branch **is**
an environment — when pushing to it puts code somewhere. Repositories that ship as **packages** have
no environments to model, and for them the ladder is ceremony: branches that gate nothing, deploy
nothing, and drift silently out of date.

| | **Product profile** | **Framework profile** |
|---|---|---|
| Example | a host repo from the skeleton (Sowing.me / KITG) | **uBixCore itself** |
| Ships as | a deployed image | Composer + npm packages, and the skeleton |
| Long-lived branches | `dev` → `staging` → `main` | **`main` only** |
| A release is | a promotion to `main` | **a `v*` tag** |
| Older versions | the running deployment | consumers pin `^0.3`; backport from a tag only if needed |
| Gate runs on | `dev`/`staging`/`main` pipelines | **every branch and tag** (`.on_any_ref`) |
| Promotion jobs | yes (`bin/promote.sh`) | none |

### Product profile

Everything below applies as written: the three-level topology, the sync flow, and
[Promotion to Production](#promotion-to-production-dev--staging--main--prod). The skeleton ships this
configuration, so a new host gets it by default and should keep it.

### Framework profile

Trunk-based. One long-lived branch, `main`:

```
main                             # trunk; MR-only, protected
 │
 ├─ feat/<slice>                 # short-lived, one owner
 ├─ fix/<slice>
 └─ docs/<slice>
```

- **Releases are tags, not branches.** A `v*` tag publishes the packages. Consumers resolve `^0.3`
  from the registry and never look at your branches, so a branch per version buys nothing.
- **Maintenance branches are cut on demand, from a tag** — `0.2.x` only when a consumer pinned there
  needs a backport. They are created when that happens, never kept standing "just in case".
- **The gate runs on every ref**, so an MR is genuinely validated before it lands. This is the part
  product repos most often get wrong: `only: [dev]` on every job means an MR runs *nothing*, and
  "MR-only" then describes a review ritual rather than a check.
- **No promotion, no environments, no `staging`.** `bin/promote.sh` and `bin/deploy.sh` live in this
  repo as *templates hosts inherit through the skeleton* — they are not run here.

Sections that do not apply to this profile: [Sync Flow](#sync-flow) (there is no project-branch
level), [Cadence](#cadence), and [Promotion to Production](#promotion-to-production-dev--staging--main--prod).
[The Core Rule](#the-core-rule), [Concurrent Agent Sessions](#concurrent-agent-sessions) and the
[pre-push hook](#the-pre-push-hook-the-hard-gate) apply to both.

> **Convention below:** `<trunk>` means **`dev`** in the product profile and **`main`** in the
> framework profile. Sections that name `dev` literally are product-profile sections.

> **Why uBixCore moved (2026-09-12).** It carried `dev`/`staging`/`main` from when it deployed
> Sowing.me. OSS-10 moved that out; its own `.gitlab-ci.yml` header now reads *"There is no runtime
> image, deploy or promote here."* At the point of the change **no job referenced `dev`, `staging` or
> `main`** — the gate was already `.on_any_ref` and publish already `only: [tags]` — and `staging`
> and `main` were the same commit, eight behind `dev`. The ladder was inert, so it went.

## Branch Topology

*Product profile. For the framework profile see [above](#framework-profile).*

Three levels:

```
dev                              # mainline integration branch
 │
 └─ <project>                    # long-lived project branch (e.g. internal-admin-v2)
     │
     ├─ <initials>/<project>-<slice>   # one dev owns
     ├─ <initials>/<project>-<slice>
     └─ <initials>/<project>-<slice>
```

| Level | Lifespan | Owner | Who can push |
|---|---|---|---|
| `dev` | permanent | the team | merges only, via PR |
| Project branch | weeks–months | project lead | merges only, via PR |
| Feature branch | hours–days | one dev | that dev (force-push allowed) |

These three are where work happens. `staging` and `main` sit *downstream* of `dev` and are written only by the promotion jobs — never worked on, never merged into by hand. See [Promotion to Production](#promotion-to-production-dev--staging--main--prod).

### Naming

| Branch kind | Pattern | Example |
|---|---|---|
| Project | `feature-<project-slug>` | `feature-internal-admin-v2` |
| Feature (per-dev) | `<initials>/<project-slug>-<slice>` | `cwo/internal-admin-v2-userlist` |

The per-dev prefix is the developer's initials. This makes ownership obvious at a glance in `git branch -a` listings and avoids collision with the `feature-` prefix used for project branches.

If a slice is genuinely shared between two developers (pair work that needs a real branch), name it `<initials1>-<initials2>/<project-slug>-<slice>` (e.g. `cwo-jsm/internal-admin-v2-payments-revamp`) and treat it as a shared branch — see [The Collaboration Gotcha](#the-collaboration-gotcha).

## The Core Rule

**Rebase branches you own. Merge into branches others depend on.**

Rebasing rewrites commit SHAs. Anyone branched off a rewritten branch finds their base commit orphaned and has to rebase themselves. So:

- A branch only you commit to → rebase freely (clean history, safe).
- A branch others have forked from → merge into it (preserves their bases).

Applied to the topology above:

| Branch | Sync style | Why |
|---|---|---|
| `dev` | merge only | every project depends on it |
| Project branch | merge in from `dev` | feature branches depend on it |
| Feature branch | rebase onto project | only one dev — safe to rewrite |

## Sync Flow

### 1. `dev` → project branch: **merge**

When `dev` has new commits the project wants to absorb:

```bash
git checkout internal-admin-v2
git pull
git merge dev
git push
```

This adds a merge commit to the project branch. That's intentional — rebasing `internal-admin-v2` onto `dev` would orphan every feature-branch base commit and force every dev on the project to re-rebase.

### 2. Project → feature branch: **rebase**

After step 1, each dev brings their feature branch up to date:

```bash
git checkout cwo/internal-admin-v2-userlist
git fetch origin
git rebase origin/internal-admin-v2
git push --force-with-lease
```

Always `--force-with-lease`, never `--force` — it refuses to overwrite remote commits you haven't seen, which catches the case where someone else accidentally pushed to your branch.

### 3. Feature → project: **squash merge**

Devs open a PR from their feature branch into the project branch. **Squash merge** is the standard — one commit on the project branch per feature PR. The PR title becomes the commit subject, so PR titles should be written in conventional-commit style (e.g. `feat(admin): add user list pagination`).

Rebase-and-merge is intentionally not used here — it would require every intermediate commit to be clean and passing CI, which is too much ceremony for the per-dev → project hop. Devs are free to make messy WIP commits on their own branches; the squash collapses them at the PR gate.

### 4. Project → `dev`: **squash merge**

When the project wraps, the project branch lands on `dev` via a **squash merge**. One commit on `dev` per project. The PR title becomes the commit subject; the PR body becomes the commit body and should summarize what shipped (mirrors the CHANGELOG.md entry for the same release).

Why squash rather than a merge commit:

- The periodic `dev → project` sync merges (step 1) would otherwise land on `dev` as recursive-looking commits (`Merge dev into feature-<project>`).
- Bisection at the `dev` level wants project-grained units, not feature-grained.
- Reverting a project becomes one clean `git revert <sha>` instead of `git revert -m 1 <merge-sha>`.
- Consistent with step 3 — one rule end-to-end: "PR merges squash."

Per-feature history is preserved on the project branch itself. Don't delete the project branch immediately after merge; keep it for at least one release cycle so the granular history is still browsable.

## Cadence

### When to merge `dev` into the project branch

The project lead owns this. Devs should not initiate `dev → project` merges individually.

Four triggers, in order of how often they fire:

| Trigger | When | SLA |
|---|---|---|
| **Weekly floor** | Every Monday (or first work day of the week), regardless of churn | Same day |
| **Shared-framework alarm** | Any merge to `dev` touches `php/Ubix/*` or `ts/Ubix/*` | Within 48h, all active project leads sync |
| **File-overlap signal** | Merge to `dev` touches a file this project also touches | Within 24h |
| **Pre-PR mandatory** | Before opening the project → `dev` PR | Always — non-negotiable |

The shared-framework and file-overlap signals can be detected mechanically with `git log dev --stat --since="<last sync>"` — grep for `php/Ubix/`, `ts/Ubix/`, or the specific paths the project touches.

### Heads-up norm for structural changes

Project leads should post in the team channel **before** landing structural changes that affect other active projects: renames in shared code, signature changes to widely-used services, new PHPCS rules, framework-level refactors. Thirty seconds of warning saves hours of conflict resolution across other projects.

### When devs rebase their feature branch onto the project branch

- **Before opening a PR** — always.
- **After the project lead merges `dev` into the project branch** — coordinate via team channel so everyone rebases together.
- **Daily-ish during active development** — keeps conflict surface small.

## The Collaboration Gotcha

**Never rebase a feature branch someone else is also committing to.**

If two devs need to work on the same slice:

- **Preferred:** pair on it on one dev's branch, or split the slice so each owns a separate branch.
- **If you must share:** treat it as a shared branch — merge in from the project branch instead of rebasing, and don't force-push.

## Concurrent Agent Sessions

Everything above assumes one working tree that a developer moves through serially — check out a branch, work, check out the next. AI agent sessions break that assumption: two (or more) can run **at the same time in the same repo**, and they lack the ambient coordination — Slack, standups, hallway — that keeps humans from colliding. Two problems follow, each with its own fix.

### The physical problem: one working tree, two writers

A git branch is only a ref; the working tree is a single mutable directory. If two concurrent sessions share one checkout, one session's `git checkout` / `rebase` / `stash` swaps files out from under the other's uncommitted edits mid-task. **Branches do not isolate this** — the branch topology is entirely logical; the working tree is physical and singular.

**Rule: one `git worktree` per concurrent session.** Each session gets its own checkout of the same repo; all worktrees share one `.git`, one set of refs, and push to the same `dev`.

```bash
# Preferred: one command bundles fetch + correct path + correctly-named branch,
# and refuses to run if the lane isn't claimed in AGENTS-COORD.md.
php bin/ubix code:worktree <lane> <slice>   # → ../ubixcore-worktrees/<lane> on feature-<lane>-<slice> off origin/<trunk>

# ... the agent works entirely inside ../ubixcore-worktrees/<lane> ...

# Once the slice has landed on dev (see the merge protocol below)
git worktree remove ../ubixcore-worktrees/<lane>
```

The command is the recommended path; the equivalent raw form is `git fetch origin && git worktree add ../ubixcore-worktrees/<lane> -b <prefix>/<slice> origin/<trunk>`.

Conventions:

| Aspect | Convention |
|---|---|
| Location | `../ubixcore-worktrees/<lane>` — a dedicated sibling parent dir keeps every agent checkout out of the primary tree and grouped under one folder |
| `<lane>` | the coordination lane name (see [The coordination contract](#the-logical-problem-no-ambient-coordination)), e.g. `cf-tipping`, `reg` |
| Branch | created with the worktree (`-b <prefix>/<slice> origin/<trunk>`), owned solely by that session |
| Lifecycle | `worktree add` at session start → `worktree remove` once the slice is on `dev`; `git worktree prune` clears stale entries |

Because all worktrees share one `.git`, the branch topology, the [Core Rule](#the-core-rule), the [Sync Flow](#sync-flow), and the PR gates below are all unchanged — a worktree is physical isolation, not a separate repo.

### The logical problem: no ambient coordination

Concurrent agents can't feel a Monday sync or notice a peer editing the same file. That awareness has to be written down. Every repo with concurrent agent sessions keeps a root **`AGENTS-COORD.md`** — the living coordination contract, read before branching, before editing a shared file, and before merging to `dev`. It carries three things:

- **Lane table** — one row per agent: role/scope + owned branch prefix. A new agent adds its row and claims a distinct prefix *before* touching anything.
- **Path ownership** — which paths each lane edits freely, plus the shared-file set that must be claimed in the log first: root `CHANGELOG.md`, `README.md`, `CLAUDE.md`, and the framework trees `php/Ubix/*` / `ts/Ubix/*`.
- **Append-only live log** — timestamped claims and landing announcements; never rewrite another agent's entries.

**The live file is untracked — per-sandbox state, never pushed (decision: 2026-07-30).** The lane table and log coordinate the agents of *one* sandbox's checkout; on another sandbox they are noise, and committing them dragged session state through cross-lane merge races that repeatedly corrupted log entries on origin. The *contract* is versioned as the committed **`AGENTS-COORD.template.md`**; a fresh sandbox seeds its live copy once with `cp AGENTS-COORD.template.md AGENTS-COORD.md` (the file is `.gitignore`d). Consequences: the live file is no longer in the shared-file claim set, and one lane's coordination edits can never trip another lane's push gate.

`AGENTS-COORD.md` is the per-sandbox *instance* of this pattern; this section is the *standard* it conforms to, and the template's own header carries the seeding instructions.

### Two agents on two different projects

The common case: two agents own two different project branches. The topology and the [Core Rule](#the-core-rule) are unchanged — each project branch merges in from `dev`, each agent rebases its own feature branch onto its project branch. What concurrency *adds*:

- **Separate worktrees**, one per agent (the physical rule above).
- **The collision set becomes explicit rather than felt.** For two humans it's ambient; for agents it must be enumerated in `AGENTS-COORD.md`: the framework trees (`php/Ubix/*`, `ts/Ubix/*`), the per-app registration files that *every* feature appends to — `app/<App>/src/Routes.php`, `app/<App>/src/Dependencies.php` — and the root shared files. Claim them in the log before editing; on a merge conflict the resolution is almost always **keep both sides** (two features each added their own route / DI binding / CHANGELOG bullet).
- **The merge-to-`dev` window is serialized** — see below.

### One land path: MR-only `dev` (2026-07-30)

**Every landing on `dev` goes through a merge request — no direct pushes, no exceptions, Christopher included.** (Decision 2026-07-30, benchmark SB-36 phase 2; supersedes the old fast-path/project split below. Server-side enforcement arrives when the settings listed at the end of this section are flipped — SB-36 tracks go-live; until then the flow is the contract to follow voluntarily.) The flow:

1. Work on a `feature-*` / `<initials>/*` branch in your worktree; group related slices (one MR per logical unit, per the bundling guidance).
2. Push the branch and open the MR in one step with git push options — this is the sandbox automation, no tooling needed:
   ```bash
   git push -o merge_request.create -o merge_request.target=<trunk> origin <branch>
   ```
3. The MR pipeline runs **`cspell-knip-mr`** (the pre-merge leg of the machine gate — cspell over `docs/`, `README.md`, `CHANGELOG.md` and every `*Js` workspace, plus knip; see [`code-review.md` §1](code-review.md#1-the-mechanical-gate)) and **`claude-review-mr`**: Claude reviews the full MR diff and posts each finding as an **unresolved discussion thread**; with the project setting *"all threads must be resolved before merge"*, the MR cannot merge until every finding is **fixed-and-resolved** or **resolved with a written dismissal reason**. Claude never approves — a human does; rejection is simply blocking threads. Findings are catches, not violations (SB-37) — fixing them fast is what the board scores.
4. A human approves; merge (GitLab merge commit ≙ the old `--no-ff` landing).

**Push deliberately — every push to an open MR costs a full review.** The `claude-review-mr` job is a metered API call that re-reads the **entire** MR diff (against the merge base) on **every** push, so cost is `diff size × pushes` — and a rebase or force-push counts. Working a review round therefore means: fix **all** the threads, run the gate **once**, push **once**. Do not push per finding, do not push to find out whether CI is green (run `php bin/ubix code:review` locally — the hook runs it anyway), and squash "fix typo" follow-ups locally instead of paying for a review of each. For a genuine handoff/backup push use `git push -o ci.skip`, but **never** on a push that should be reviewed: a skipped pipeline posts no thread, and zero threads reads as a clean bill rather than an absent review. Full cost model, measured numbers and the structural levers: [`code-review.md` §6](code-review.md#6-cost--the-ai-review-is-metered-so-push-deliberately).

The `code:review` pre-push hook still runs on every push (now guarding your feature branch), and server-side branch protection on `dev` (push: **No one**; merge: Developers + Maintainers) makes the MR path mechanical, not honor-system. The old direct fast-path served the bootstrap era; MR-only closes its gap — review findings used to arrive *after* origin had the code, with no enforcement point. Now they arrive on the MR, where the merge is the enforcement point.

**Known gap (SB-36 follow-up), part-closed 2026-08-24:** with "pipelines must succeed" deliberately OFF, the machine `code:review` gate still has no *merge-time* **enforcement** — the pre-push hook guards pushes client-side only (and it is per-clone opt-in: `git config core.hooksPath .githooks`), so the Claude-review threads remain the sole mechanical merge blocker. What changed is **visibility**: the chartered follow-up's first slice landed as **`cspell-knip-mr`**, so one leg of the gate now runs on every MR pipeline and a red job is at least *visible* before the merge. Read it — a red `cspell-knip-mr` means the merge will red `dev`'s `lint-and-test`, which stage-gates `deploy-dev` and stops `dev` being redeployed for everyone (that is exactly what happened on 2026-07-31 and again on 2026-08-24). Remaining: flip "pipelines must succeed" ON to make it blocking, and decide whether the rest of the gate's legs are worth an image build on MR pipelines (they all need `build-dev` first; the cspell/knip leg needs neither an image nor a secret, which is why it went first). The Claude review job itself stays `allow_failure` so an API outage never blocks a merge — machine gate blocking, AI review advisory-but-thread-blocking.

**Break-glass (and why there is no standing exception).** Nobody — including the Maintainer who owns the tooling — keeps direct push access. The emergency path is MR-first even for hotfixes: a broken pipeline cannot deadlock a merge ("pipelines must succeed" is deliberately OFF and the review job is `allow_failure`, so an MR with resolved threads merges even while CI is down). For a true break-glass (GitLab itself misbehaving), the Maintainer toggles the `dev` protection, pushes, toggles it back, and announces the act in `#the original monorepo-alerts` — the toggle is deliberate, audited, and temporary, which is exactly what separates an emergency from an exception.

**Working the review threads — every finding gets a recorded decision.** A resolved thread means "a decision was recorded", not "fixed". Agents resolve a thread themselves once they have replied with one of the markers below (as their bot account, never the owner's); a thread with no answer stays open. The *what* must be machine-readable, because later reporting (fix-rate vs dismiss-rate vs deferral debt per finding class) parses the resolution comments. **Start every resolution comment with one of three markers:**

| Marker | Means | Example |
|---|---|---|
| `Fixed:` | code changed | `Fixed: abc1234 — escape scoped to slash-then-letter` |
| `Dismissed:` | deliberate no-action; the reason IS the record | `Dismissed: requires GitLab to trim before quick-action matching, which it does not; cosmetic cost outweighs` |
| `Deferred:` | accepted, tracked for later | `Deferred: charter in docs/projects/x/README.md — needs the v2 schema first` |

Judgment, not severity tags, decides which: **fix** anything cheaper to fix than to argue about, and all correctness/security regardless of tag; **dismiss** the speculative and the stylistic — with a written reason; **defer** only with a real tracking home. Severity is the reviewer's estimate, not your verdict. If you find yourself writing the same dismissal twice, say so — recurring dismissals are calibration data (the class belongs in the review prompt's out-of-scope list), and recurring *mechanical* fixes are gate-tool candidates. Never resolve a thread silently: an empty resolution is indistinguishable from a shrug, and the reporting will count it as one.

**Server-side settings this depends on** (project settings, one-time): protected branch `dev` → push **No one** / merge **Developers+Maintainers**; Merge requests → **all threads must be resolved** ✓; **Pipelines must succeed** — still OFF, and the one setting that would turn `cspell-knip-mr` from visibility into enforcement; CI variable `ANTHROPIC_API_KEY` reachable by MR pipelines (unprotect the masked variable, or add an MR-scoped copy); optional `CLAUDE_REVIEW_GITLAB_TOKEN` (api-scope) for thread posting — falls back to the repo `.env` GitLab token.

### Merge sign-off (2026-09-30)

**Every merge needs a human sign-off: the MR's Approve button, or a 👍.** It is enforced by a
pipeline job, not by GitLab approval rules: those need a paid tier, and they cannot cope with a
team where every MR is opened under the owner's account (an author cannot approve their own MR,
so nothing would ever merge).

- **The check:** `bin/ubix ci:requireApproval` (`Ubix\Service\Ci\MergeApprovalService`). It
  passes when the MR has an Approve or a 👍 from a user listed in `MERGE_APPROVAL_OWNERS`, the
  author included; the log says **SELF SIGN-OFF** when the author is the only one, so a
  pushed-through merge is recorded rather than invisible. Anyone else's sign-off (another user,
  Kelpie, an agent's bot token) is logged as not counted: no account outside the list can
  satisfy the gate. No owners listed fails the job. On a branch pipeline,
  which carries no MR variables, it finds the MR by source branch.
- **It blocks because "Pipelines must succeed" is ON.** Without that setting the job is only a
  red mark.
- **GitLab does not re-run a pipeline when someone approves.** Where uBixOps is deployed
  and the project's merge-request webhook points at it, approving retries the
  `require-approval` job by itself, and so does a 👍 when the webhook's **Emoji events** trigger
  is on ([`../architecture/ubix-ops.md`](../architecture/ubix-ops.md)).
  Otherwise: approve, then **retry the `require-approval` job**; the pipeline turns green
  and the merge button unlocks.
- **It fails open.** No token, or GitLab unreachable: the job passes with an `UNVERIFIED` line
  rather than holding every merge hostage to an outage.
- **Agents never approve, never 👍 and never merge.** They do not press the merge button or arm
  merge-when-pipeline-succeeds; the owner merges. Agents post as their own bot account, and the
  owners-only check means a bot's sign-off would not count anyway; the rule is what keeps the
  click meaning "the owner looked at this".
- **Honest limit:** a guard against merging in haste, not a security boundary. A click costs
  nothing to make unread.

**Wiring a host:** a job in the last stage of the MR pipeline (after any notify-on-failure job,
which would otherwise page for every unapproved push), running `php bin/ubix ci:requireApproval`
with `MERGE_APPROVAL_OWNERS` set and a token in `APPROVAL_GITLAB_TOKEN` (falls back to
`AI_REVIEW_GITLAB_TOKEN`: a project access token, Reporter, `api` scope, masked, **not**
protected, because MR branches are unprotected). Then turn **Pipelines must succeed** on.
uBixCore's own `require-approval` job in `.gitlab-ci.yml` is the reference.

### The fast-path (feature/slice → `dev`) — HISTORICAL (superseded by MR-only, 2026-07-30)

Two humans rarely `git push origin dev` in the same second; two agents easily can. This protocol serializes the window and keeps it short:

1. **Announce** in the `AGENTS-COORD.md` log: `LANDING <branch>`.
2. `git checkout dev && git pull --ff-only origin dev`.
3. `git merge --no-ff <branch> -m "Merge branch '<branch>' into dev"` — `--no-ff` preserves the per-slice grouping.
4. `git push origin dev` — the **pre-push hook runs `ubix code:review` automatically** and refuses the push if it's red. Keep the pull→push window minimal.
5. If the push is rejected as non-fast-forward (a peer landed first): `git pull --ff-only`, re-merge, re-push. A real conflict here lives in a shared file — resolve it keeping **both** sides.
6. Mark `LANDED <branch>` in the log and `git worktree remove` the session's checkout.

### The pre-push hook (the hard gate)

The committed `.githooks/pre-push` runs the fast gate (`ubix code:review
--phpunit=off`) on **every branch push** (`refs/heads/*` — deletions and tag
pushes are skipped; tags carry already-gated commits and gating them would
block the CalVer release flow). Install once per clone — the setting lives in
the shared `.git/config`, so it covers every worktree:

```bash
git config core.hooksPath .githooks
```

**The hook is bypassable only in the mechanical sense — doing so is forbidden.
There is NO emergency bypass** (Christopher's standing rule, 2026-07-22):
never `git push --no-verify`. If the gate is red on something you didn't
write — typically another lane's uncommitted WIP over-scanned in a shared
checkout — the fix is coordination, not bypass: use the `AGENTS-COORD.md` log
to get that lane green and their file committed, then push cleanly. Server-side
branch protection is the mechanical backstop; the rule above is absolute
regardless.

History: the hook originally gated only pushes to `refs/heads/dev`, with
feature branches ungated "so WIP force-pushes stay fast" — correct in the
direct-push era, but under MR-only nobody pushes `dev`, so the local gate had
silently stopped firing entirely (found on MR-only day one when CI caught
violations the hook had passed). Every-branch scope restored the gate
(2026-07-31).

## Per-Dev PR Gate (Feature → Project)

Every PR from a per-dev feature branch into the project branch must satisfy **all** of the following before it can merge:

| Gate | Requirement |
|---|---|
| **CI** | `php bin/ubix code:review` must pass. |
| **Reviewers** | At least **one** teammate approval. Any project member counts; doesn't have to be the project lead. |
| **Branch up-to-date** | Feature must be rebased on the current project-branch HEAD. No stale base commits. |
| **Conversations resolved** | Every reviewer comment marked resolved before merge — forces explicit acknowledgement rather than silent ignore. |
| **Linked ticket** | PR description references the issue/ticket the work belongs to (traceability for retrospectives and audits). |

This is the lighter of the two gates — the heavier review happens at project → `dev`.

## Project → `dev` PR Gate

The heavier gate. The project branch represents weeks/months of work, and once it lands on `dev` every other project will start to depend on it. Every gate from the [feature PR gate](#per-dev-pr-gate-feature--project) carries forward, plus the following:

| Gate | Requirement |
|---|---|
| **Reviewers** | **Project lead + at least one other teammate.** Lead owns the holistic shape; the outside reviewer catches what the lead has been too close to see. |
| **Branch up-to-date with `dev`** | The latest `dev` must be merged into the project branch before the project → `dev` PR can merge. |
| **Docs / README / CHANGELOG in sync** | Per the standing rule, doc updates land with code. If the project shipped user-visible behavior and `CHANGELOG.md` (Keep a Changelog format) wasn't touched, the PR doesn't merge. Same for `README.md` and any `docs/` pages that describe the affected surface. |
| **Migration check** | If the project added DB migrations, they exist in the migrations directory and conform to [`docs/standards/migrations.md`](migrations.md) — naming, idempotency, rollback notes. |
| **No unresolved TBDs in touched standards docs** | If the project edited any `docs/standards/*` files and left `**TBD**` markers in place, those must be resolved before merge — or explicitly deferred with a tracked ticket linked in the PR description. |
| **Linked release / milestone** | PR description references the release version (CalVer `YYYY.MM.MICRO`) or milestone the project belongs to. |

## Promotion to Production (`dev` → `staging` → `main` → prod)

*Product profile only — framework repos have no environments to promote into.*

Everything above ends at `dev`. This section is what happens after: two more branches, **two deliberate human clicks**, and — today — no mechanical correctness gate in front of prod.

```
dev ──────auto──────▶ staging ─────manual─────▶ main ─────manual─────▶ prod pods
    promote-to-staging       promote-to-main          deploy-prod
    (after green deploy-dev)      (click 1)             (click 2)
```

`staging` and `main` are **not** branches anyone works on or merges into by hand. They are written only by the promotion jobs. Neither appears in the [three-level topology](#branch-topology) above because neither is a place work happens.

### 1. `dev` → `staging` — automatic

`promote-to-staging` runs `on_success` after a green, health-gated `deploy-dev`, so "promoted" means dev is **deployed and verified Ready**, not merely built. Safety logic lives in `bin/promote-to-staging.sh`.

- **`staging` is a zero-unique-content mirror of `dev`**, written only by this job via lease-guarded force push (it aborts on a concurrent staging write).
- **Never press the GitLab merge button on `staging`.** A merge node dirties the mirror — the exact failure mode of 2026-07-29. If you need to promote by hand, press `promote-to-staging-manual`.
- **Destructive-migration carve-out:** a migration marked `Destructive:` in the batch **halts the promotion green** and notifies, rather than auto-flowing it toward prod. *Halting is not a failure.* A human reviews, then presses `promote-to-staging-manual-destructive`, which re-runs the same script with `PROMOTE_ACK_DESTRUCTIVE=1` (attributed and Slack-posted).
- **Backwards guard:** halts if `staging` already contains the source SHA.
- "Allowed to force push" on protected `staging` is a **recovery-time** toggle (enable, heal, disable) for non-ff syncs — not a standing setting.

### 2. `staging` → `main` — manual, click 1

`promote-to-main` / `promote-to-main-destructive` are manual buttons on a **`staging`** pipeline (`bin/promote-to-main.sh`). This is the release act, and it is never automatic.

- **Fast-forward ONLY.** `main` is promotion-only prod history and is **never** forced. A non-fast-forward means `main` diverged from the promotion flow, and a human must establish *how* before anything ships. This is the deliberate difference from `staging`, which is a mirror that may heal over merge nodes.
- **Backwards guard:** pressing the button on an old staging pipeline halts green rather than moving `main` backwards.
- **Destructive carve-out:** same shape as staging — halt green unless `PROMOTE_ACK_DESTRUCTIVE=1`, which the `-destructive` twin supplies.
- **Ops prerequisites:** a token with push access to protected `main` (`GITLAB_PROMOTE_TOKEN` CI variable, else `GITLAB_OAUTH_TOKEN` from `.env`), plus `SLACK_API_ENDPOINT` for the notify.

**Promoting to `main` does not deploy anything.** It moves the branch. The rollout is click 2.

### 3. `main` → prod — manual, click 2

On the resulting `main` pipeline:

| Button | Gated on | When |
|---|---|---|
| `deploy-prod` | `build-prod` + `migrate-verify-prod` | the normal path |
| `deploy-prod-destructive` | the above **+** manual `migrate-apply-prod-destructive` | un-playable until that apply is green |
| `deploy-prod-emergency` | `build-prod` only | bypasses the migrate gate — emergencies |
| `emergency-rollback-prod` | — | reverts every app Deployment to its previous ReplicaSet |

`migrate-verify-prod` is read-only and **blocking** (`allow_failure: false`), so prod schema drift makes the deploy button un-playable. prod runs no test jobs — staging is the test bed.

### What is deliberately NOT gated

There is **no mechanical correctness gate in front of prod today.** A green pipeline proves pods *serve*; it does not prove the release is *correct*. The sign-off queue that would close this — a pinned-release-candidate queue surfaced in an internal admin console with one-click Ship — is designed in [`docs/projects/continuous-delivery/release-gate.md`](../projects/continuous-delivery/release-gate.md) and is **not built** (status: planned/design). Until it is, the confidence gate is human judgement plus staging soak time. Treat the two clicks as the real gate, and do not press either one on someone else's behalf without knowing what is in the batch.

### Cutting a CalVer release

The version marker is the CHANGELOG heading: the release cut moves the accumulated `[Unreleased]` entries under a new `## [YYYY.MM.MICRO]` heading (scheme in [README § Versioning](../../README.md#versioning)). It lands on `dev` like any other change — feature branch, MR, review — and reaches prod through the promotion path above.

One mechanical note: the cut is the **one** operation that legitimately deletes lines from `CHANGELOG.md`, so the pre-push deletion guard has to be acknowledged for that push:

```bash
UBIX_CHANGELOG_DELETIONS_OK=1 git push -o merge_request.create -o merge_request.target=<trunk> origin <branch>
```

That acknowledgment is scoped to the CHANGELOG guard only — it is not a `code:review` bypass, and there is still no such thing.

> **Not yet decided:** who owns the release cut and on what cadence. CalVer's `MICRO` is a per-month counter, which implies more than one release a month, but nothing sets the rhythm. Pin this down before the queue in `release-gate.md` is built, since the queue assumes a release boundary exists.

## Future Automation (Next Iteration)

This document codifies the workflow as norms. The next iteration of this standard will move whatever is mechanically checkable into the `ubix` CLI, so the rules are enforced by tooling instead of remembered by humans. Candidate automations:

| Rule | Possible CLI surface |
|---|---|
| Branch naming convention | `ubix branch:start <slice>` — creates `<initials>/<project-slug>-<slice>` off the current project branch; rejects names that don't match the pattern. |
| ~~One worktree per concurrent session~~ | **Shipped (v0.5)** as `ubix code:worktree <lane> <slice>` — creates `../ubixcore-worktrees/<lane>` off `origin/<trunk>` with the correctly-named branch, verifies the lane is claimed in `AGENTS-COORD.md`, and prunes stale worktrees. |
| Unbypassable `code:review` gate on `dev` | The committed `.githooks/pre-push` gate (shipped in v0.4) is client-side and `--no-verify`-bypassable. When unsupervised agents run, promote it to **server-side branch protection** on `dev` requiring the `code:review` status check — enforced by the forge, not by a local hook. |
| Pre-PR sync mandatory | `ubix branch:check` (or a pre-push hook) — refuses to push if the branch is behind the project branch or `dev`. |
| Weekly + file-overlap + framework-alarm sync triggers | `ubix branch:sync-status` — for each active project branch, reports whether it's overdue (weekly floor breached), whether `dev` touched `php/Ubix/*` or `ts/Ubix/*` since last sync, and whether `dev` touched any file this project also touches. |
| Docs / README / CHANGELOG in-sync gate | `ubix code:review --changelog-check` — flags PRs that change user-visible behavior without touching `CHANGELOG.md` or the relevant `docs/` page. |
| Migration gate | Existing `ubix migrations:*` commands extended with a PR-gate mode that fails if new migrations don't conform to [`docs/standards/migrations.md`](migrations.md). |
| No unresolved TBDs in touched standards docs | `ubix code:review` extension — scans touched `docs/standards/*` files for `**TBD**` markers and fails if any remain unresolved or unticketed. |
| Heads-up norm for structural changes | `ubix branch:notify-structural` — detects when a PR touches `php/Ubix/*` or `ts/Ubix/*` and prompts the author to post in the team channel before merging. |

Until those land, the gates above are enforced by reviewer discipline + PR-template checklists.

## Quick Reference

```bash
# Start work on a new slice
git checkout internal-admin-v2
git pull
git checkout -b cwo/internal-admin-v2-userlist

# Stay current during the day
git fetch origin
git rebase origin/internal-admin-v2
git push --force-with-lease

# Project lead: pull dev into the project branch
git checkout internal-admin-v2
git pull
git merge dev
git push

# After the project lead lands dev → project, every dev rebases
git checkout cwo/internal-admin-v2-mybranch
git fetch origin
git rebase origin/internal-admin-v2
git push --force-with-lease
```

## Decision Log

Initial decisions resolved during 2026-05-18 review:

1. **Branch naming** — `feature-<slug>` for project branches, `<initials>/<slug>-<slice>` for per-dev branches.
2. **Feature → project merge style** — squash merge; PR titles in conventional-commit style.
3. **Project → `dev` merge style** — squash merge; project branch kept around at least one release cycle for granular history.
4. **Feature PR gate** — CI green + 1 reviewer + up-to-date + conversations resolved + linked ticket.
5. **Project PR gate** — feature-gate carryovers + project lead + 1 other reviewer + branch up-to-date with `dev` + docs/README/CHANGELOG in sync + migration check + no unresolved TBDs in touched standards + linked release/milestone.
6. **Sync triggers** — weekly floor + shared-framework alarm (`php/Ubix/*`, `ts/Ubix/*`) + file-overlap signal + mandatory pre-PR sync; project-lead heads-up norm for structural changes.

## Version History

| Version | Date | Author | Changes |
|---|---|---|---|
| 0.1 | 2026-05-18 | Christopher W. Olsen (draft) | Initial draft for review |
| 0.2 | 2026-05-18 | Christopher W. Olsen | Resolved all six open questions: branch naming, both merge styles, both PR gates, and sync triggers |
| 0.3 | 2026-05-18 | Christopher W. Olsen | Added Future Automation section flagging that mechanically-checkable gates will move into the `ubix` CLI in the next iteration |
| 0.4 | 2026-07-08 | Christopher W. Olsen | Added Concurrent Agent Sessions section: worktree-per-session physical-isolation rule, the `AGENTS-COORD.md` coordination contract, two-different-projects collision set, and the two land paths — a **fast-path** (feature/slice → `dev` via direct `--no-ff` merge, gated by the committed `.githooks/pre-push` running `code:review`) plus the reserved **project → `dev`** PR+human boundary; added `ubix branch:worktree` and server-side branch-protection automation candidates |
| 0.5 | 2026-07-08 | Christopher W. Olsen | Shipped the worktree bootstrap as `ubix code:worktree <lane> <slice>` (verifies the lane is claimed in `AGENTS-COORD.md`, fetches origin, creates the branch under `../ubixcore-worktrees/<lane>`); promoted it to the recommended path in the worktree section and marked the Future Automation row done |
| 0.6 | 2026-07-28 | Christopher W. Olsen | `--no-verify` wording aligned with the standing never-bypass rule (2026-07-22): the "emergency escape" allowance is removed — a red gate is resolved by coordination via `AGENTS-COORD.md`, never bypassed; server-side branch protection stays on the roadmap as the mechanical backstop (benchmark item SB-01/SB-12) |
| 0.7 | 2026-07-30 | Christopher W. Olsen | `AGENTS-COORD.md` goes **untracked** (per-sandbox live state, seeded from the committed `AGENTS-COORD.template.md`) — committing live coordination state caused cross-lane merge races and corrupted log entries on origin; removed the live file from the shared-file claim set |
| 0.8 | 2026-07-30 | Christopher W. Olsen | **MR-only `dev`** (SB-36 phase 2): every landing via merge request — direct pushes end, Christopher included; `claude-review-mr` posts findings as blocking discussion threads; **thread-disposition convention**: every resolution comment starts `Fixed:` / `Dismissed:` / `Deferred:` (machine-parsed by later reporting — resolved ≠ fixed); fast-path section marked historical; server-side settings documented |
| 0.9 | 2026-07-31 | Christopher W. Olsen | Pre-push hook section rewritten for the every-branch scope shipped 2026-07-31 (refs/heads/* only; tags/deletions skipped); the standing **no `--no-verify`, no emergency bypass** rule restored as live instruction after the first rewrite accidentally demoted it to version history (caught by the MR review) |
| 0.10 | 2026-08-05 | Christopher W. Olsen | Land-path section gains **push deliberately**: the MR review is metered and re-reads the whole diff on every push (cost ≈ diff size × pushes; rebases and force-pushes count), so a review round is fix-all-threads → gate once → push once; never push to test CI; `-o ci.skip` for handoff pushes only, never for one that should be reviewed. Cost model and levers in `code-review.md` §6 |
| 0.11 | 2026-08-14 | Christopher W. Olsen | Added **Promotion to Production** (`dev` → `staging` → `main` → prod): the automatic dev→staging mirror promotion and its destructive-migration halt, the two deliberate manual clicks (`promote-to-main`, then `deploy-prod`), the fast-forward-only/never-forced rule for `main` vs staging's lease-guarded mirror, the prod button matrix, and an explicit statement that **no mechanical correctness gate exists in front of prod** (the `release-gate.md` sign-off queue is still design-only). Also documents the CalVer release cut and its `UBIX_CHANGELOG_DELETIONS_OK=1` acknowledgment. Written because the only authoritative description of promotion lived in `bin/promote-to-main.sh` + `.gitlab-ci.yml` comments, and `continuous-delivery/plan.md` §5 described the shipped job as unbuilt future work |
| 0.12 | 2026-08-24 | Christopher W. Olsen | The machine gate gains its first **pre-merge** leg: `cspell-knip-mr` runs on every MR pipeline, so an MR is no longer reviewed by Claude and nothing else. Records why it is visibility and not yet enforcement (**Pipelines must succeed** is still OFF — now listed with the other server-side settings), why that leg went first (no built image, no secret; the rest need `build-dev`), and what a red job predicts: a merge that reds `dev`'s `lint-and-test`, which stage-gates `deploy-dev` and stops `dev` being redeployed for everyone. Written after that happened on 2026-07-31 and again on 2026-08-24 |
| 0.14 | 2026-09-30 | Christopher W. Olsen | **Merge sign-off.** Every merge needs an Approve or a 👍, enforced by the `ci:requireApproval` job plus *Pipelines must succeed*, not by GitLab approval rules (paid tier, and they deadlock when every MR is authored by the owner). Owner self-sign-off allowed and logged; fails open; agents never approve. |
