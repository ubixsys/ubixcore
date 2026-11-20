# AI review in CI — `ci:aiReview`

**Version:** 2.1
**Date:** 2026-09-30
**Status:** Active

A review of each merge request by an LLM, run on the GitLab runner, whose findings must each
get a recorded decision before the MR merges. Each finding becomes its own resolvable thread — inline on its
line when the diff has that line — plus one summary thread, posted even when the verdict is
"Nothing worth raising". With the project setting **All threads must be resolved**, the merge
waits until every one is resolved, by a person or by an agent that has answered it (§4); the
owner's sign-off is what says a person has looked. The model never approves and never resolves;
the job itself stays `allow_failure`, so an outage cannot wedge a merge, but a failed run
still posts a "NOT reviewed — review this one yourself" thread that must be resolved. Local
review, with whatever AI each developer uses, is separate and unaffected.

## 1. What the framework ships, and what the host owns

| Piece | Layer |
|---|---|
| `Ubix\Service\Ci\AiReviewService` — Gemini request (structured JSON), threads per finding, summary thread | uBixCore |
| `Ubix\Console\Command\Ci\AiReviewCommand` — `bin/ubix ci:aiReview` | uBixCore |
| Generic review instructions (`php/Ubix/Service/Ci/ai-review-instructions.md`): bugs, security, the host's conventions, tests; no style nits; a fixed output format | uBixCore |
| **The host's conventions file** — the rules that have caused that product's incidents | Host |
| **The CI job** — which paths are sent, which image runs it | Host |
| **The two CI variables** | Host |

The instructions the model sees are the generic ones followed by the host's file under
"This project's conventions". Write that file as rules a reviewer could check against a diff,
not as product documentation.

## 2. Setting it up in a host

### 2.1 A Gemini API key

Create one at **https://aistudio.google.com/apikey** (sign in with the Google account that
should own it, "Create API key", pick or create a Google Cloud project). No billing is needed
for the free tier.

**The free tier trains on what you send.** Google uses free-tier prompts to improve its
products; the paid tier does not. Decide that per project, and keep anything that is not
shareable out of the diff (§2.4). Credentials belong in uBixVault, never in the repo, which is
what makes this acceptable for most hosts. Free-tier rate limits are small (requests per
minute and per day); a busy day of pushes can exhaust them, and the job then fails yellow with
"rate limit reached" rather than posting.

### 2.2 A GitLab token to write the note

Project → Settings → Access tokens → **Add new token**: role **Developer**, scope **`api`**,
an expiry you will remember to rotate. Developer, not Reporter, because the job resolves
the summary of a clean review; with Reporter everything else works and a clean summary
stays open. `CI_JOB_TOKEN` cannot write MR threads.

### 2.3 The CI variables

Project → Settings → CI/CD → Variables. Both **Masked**, both **not Protected** — MR branches
are unprotected, so a protected variable never reaches an MR pipeline:

| Variable | Value |
|---|---|
| `GEMINI_API_KEY` | the AI Studio key |
| `AI_REVIEW_GITLAB_TOKEN` | the project access token |
| `AI_REVIEW_MODEL` | optional; default `gemini-flash-latest` (Google's alias for its newest Flash) |
| `AI_REVIEW_FALLBACK_MODEL` | optional; default `gemini-flash-lite-latest`, tried when the first stays busy |

Unprotected means any branch's pipeline can read them, which is why both are kept narrow:
a free-tier key, and a token scoped to this one project.

### 2.4 The job

```yaml
ai-review-mr:
  stage: lint-and-test
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
  allow_failure: true
  timeout: 10 minutes
  script:
    - |
      git fetch -q --depth=1 origin "$CI_MERGE_REQUEST_DIFF_BASE_SHA"
      git diff "$CI_MERGE_REQUEST_DIFF_BASE_SHA" HEAD -- . ':(exclude)docs' ':(exclude)composer.lock' \
        | php bin/ubix ci:aiReview --guide=bin/ci/ai-review-guide.md
```

- Diff against `CI_MERGE_REQUEST_DIFF_BASE_SHA` (the merge base), never the target branch's tip,
  or the review sees changes the MR did not make.
- **Exclude what should not leave the building**, and what only costs tokens: lockfiles,
  generated files, and any docs that hold infrastructure detail.
- Run it wherever the host's `bin/ubix` runs. kitg runs it inside the MR's own `-test` image,
  passing the variables through with `docker run -e`.

Diffs over 400,000 characters are cut at the last whole file, and the note says so.

**A host with its own CLI container** (one that passes its own file to `console()`, as kitg
does) must copy the framework's `AiReviewService` binding from `cli-dependencies.php`: it gives
the review a 240-second HTTP client. Without it the review uses the shared client's 5-second
default and every run times out.

## 3. What a run does

1. No key or token: prints "Skipped" and exits 0.
2. Empty diff: "Skipped", exit 0.
3. Sends the diff and instructions to `generateContent`, with a response schema: the answer
   is a verdict plus findings (severity, file, line, title, detail), not prose to scrape.
   A busy model (HTTP 503 or 429) is retried after 10 s and 30 s, then the fallback model
   (`AI_REVIEW_FALLBACK_MODEL`, default `gemini-flash-lite-latest`) gets the same tries.
4. **Each finding is a resolvable thread**, inline at `file:line` when that line is in the
   diff; GitLab refuses a position outside the diff, and the finding then becomes a general
   thread rather than being lost. A finding carries a fingerprint (file + title, not the
   line, so an unrelated edit above it does not re-raise it): a push that reports the same
   finding again **does not post it again**, and a thread a human resolved is never reopened.
5. **One summary thread** — verdict and a line per finding — is always posted, and is
   **edited in place while unresolved**, so many pushes before anyone looks leave one summary
   to read. Once a human resolves it, the next push opens a new one: new code, new look.
6. **A clean review resolves itself.** No findings: the summary is posted (or an open one is
   updated, since a later clean review supersedes it) and then resolved by the job, so it is
   on the record without anyone having to click it. **Findings, or a failed run, are never
   resolved by the job** — those are what a person has to look at. Resolving needs the
   **Developer** role; with a Reporter token the resolve is refused, logged, and the thread
   simply stays open, which is the safe way to fail.
7. No review at all (all models busy, a refusal, an empty answer, a bad key): the summary
   becomes **"NOT reviewed — review this one yourself"**, and the job exits 1 (yellow).

## 4. Working the threads

Resolve a thread once it is decided, and say what was decided: start the reply with
`Fixed:` (code changed), `Dismissed:` (deliberately not; the reason is the record) or
`Deferred:` (accepted, with where it is tracked). A resolved thread means "a decision was
recorded", and the reply is how anyone can tell later who decided what.

**Agents resolve the threads they have answered** (owner's decision, 2026-11-20). An agent that
fixed a finding or dismissed it with a written reason replies with the marker, then resolves the
thread, posting as its own bot account rather than a person's. It never resolves a thread it
has not answered. The trade-off, stated plainly: "All threads must be resolved" no longer proves a
person read every finding. That guarantee moves to the **merge sign-off** (branching standard §
Merge sign-off): the owner's Approve or 👍, which only users in the owners list can give and no
agent or bot can, plus the merge button, which stays a person's click. The reply on every
resolved thread is what the owner reads before signing off; a thread an agent cannot settle stays
open for the owner.

## 5. Turning it on

Project → Settings → Merge requests → **All threads must be resolved** (API:
`only_allow_merge_if_all_discussions_are_resolved=true`). Without it the threads are only
visible, not blocking.
