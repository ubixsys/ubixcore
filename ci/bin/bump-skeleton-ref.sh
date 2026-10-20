#!/usr/bin/env bash
# Bump the pinned uBixCore CI template ref in the skeleton to a new release tag.
#
# Run by the `bump-skeleton-ref` job on a v* tag pipeline:
#   bump-skeleton-ref.sh [vX.Y.Z]            tag defaults to CI_COMMIT_TAG
#   bump-skeleton-ref.sh --local vX.Y.Z      only rewrite the files under UBIX_PROJECT_DIR
#                                            (default: the repo root); no git, no API
#
# Rewrites `ref: vN.N.N` in skeleton/.gitlab-ci.yml, skeleton/docs/ci-setup.md and
# ci/README.md, commits that on branch ci/bump-skeleton-ref-<tag> cut from the
# remote's main, and opens a merge request to main. main is MR-only and every merge
# needs the owner's sign-off, so the bot never pushes to main and never approves.
#
# Needs: BUMP_GITLAB_TOKEN, a project access token on this project (Developer,
# api + write_repository), masked. The job token cannot push. Exits 1 without it,
# loudly: a bump that did nothing must never look like one that succeeded.
#
# No loop: the job runs on tag pipelines only and creates a branch and an MR, never a
# tag. Already current, a lower tag, or an existing branch/MR all exit 0 doing nothing.
set -euo pipefail

LOCAL=0
if [ "${1:-}" = "--local" ]; then LOCAL=1; shift; fi
TAG="${1:-${CI_COMMIT_TAG:-}}"
SEMVER='^v[0-9]+\.[0-9]+\.[0-9]+$'
if ! [[ "$TAG" =~ $SEMVER ]]; then
    echo "bump-skeleton-ref: '${TAG}' is not a vN.N.N release tag — nothing to do."
    exit 0
fi

ROOT="${UBIX_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
FILES=(skeleton/.gitlab-ci.yml skeleton/docs/ci-setup.md ci/README.md)
REF_RE='^([[:space:]]*ref: )(v[0-9]+\.[0-9]+\.[0-9]+)'

# Rewrite the files under $ROOT; prints "current", "lower" or "bumped".
rewrite() {
    local f cur="" top
    for f in "${FILES[@]}"; do
        [ -f "$ROOT/$f" ] || continue
        cur="$(sed -nE "s/${REF_RE}.*/\\2/p" "$ROOT/$f" | head -n 1)"
        [ -n "$cur" ] && break
    done
    if [ -z "$cur" ]; then echo "bump-skeleton-ref: no pinned ref found under ${ROOT}" >&2; return 1; fi
    if [ "$cur" = "$TAG" ]; then echo current; return 0; fi
    top="$(printf '%s\n%s\n' "$cur" "$TAG" | sort -V | tail -n 1)"
    if [ "$top" != "$TAG" ]; then echo lower; return 0; fi
    for f in "${FILES[@]}"; do
        [ -f "$ROOT/$f" ] || continue
        sed -E "s/${REF_RE}/\\1${TAG}/" "$ROOT/$f" > "$ROOT/$f.new" && mv "$ROOT/$f.new" "$ROOT/$f"
    done
    echo bumped
}

if [ "$LOCAL" = 1 ]; then
    rewrite
    exit 0
fi

TOKEN="${BUMP_GITLAB_TOKEN:-}"
if [ -z "$TOKEN" ]; then
    echo "bump-skeleton-ref: BUMP_GITLAB_TOKEN is not configured — the skeleton ref was NOT bumped to ${TAG}." >&2
    echo "bump-skeleton-ref: add a masked CI/CD variable (project access token, Developer, api + write_repository) and retry this job." >&2
    exit 1
fi

HOST="${CI_SERVER_HOST:-gitlab.brainchurts.com}"
API="${CI_API_V4_URL:-https://${HOST}/api/v4}"
PROJECT_PATH="${CI_PROJECT_PATH:?CI_PROJECT_PATH unset}"
REMOTE="https://oauth2:${TOKEN}@${HOST}/${PROJECT_PATH}.git"
BRANCH="ci/bump-skeleton-ref-${TAG}"
DEFAULT="${CI_DEFAULT_BRANCH:-main}"
ID="${CI_PROJECT_ID:?CI_PROJECT_ID unset}"

# An open MR or a pushed branch for this tag means a previous run already did the work.
OPEN="$(curl --fail --silent --show-error --header "PRIVATE-TOKEN: ${TOKEN}" \
    "${API}/projects/${ID}/merge_requests?state=opened&source_branch=${BRANCH}")"
if [ "$OPEN" != "[]" ]; then echo "bump-skeleton-ref: an MR for ${BRANCH} is already open — nothing to do."; exit 0; fi
if git ls-remote --exit-code --heads "$REMOTE" "$BRANCH" >/dev/null 2>&1; then
    echo "bump-skeleton-ref: ${BRANCH} already exists on the remote — nothing to do."; exit 0
fi

git config user.email "${GITLAB_USER_EMAIL:-ci@${HOST}}"
git config user.name  "uBixCore CI"
git fetch --quiet "$REMOTE" "$DEFAULT"
git checkout --quiet -B "$BRANCH" FETCH_HEAD

case "$(rewrite)" in
    current) echo "bump-skeleton-ref: ${DEFAULT} already pins ${TAG} — nothing to do."; exit 0 ;;
    lower)   echo "bump-skeleton-ref: ${DEFAULT} pins a newer ref than ${TAG} — not downgrading."; exit 0 ;;
esac

git add -- "${FILES[@]}"
git commit --quiet -m "chore(skeleton): pin the CI template ref to ${TAG}

Opened by the tag pipeline of ${TAG}. The skeleton's include now fetches the
shared template and ci/bin scripts from the release it was published with."
git push --quiet "$REMOTE" "$BRANCH"

curl --fail --silent --show-error --header "PRIVATE-TOKEN: ${TOKEN}" \
    --data-urlencode "source_branch=${BRANCH}" --data-urlencode "target_branch=${DEFAULT}" \
    --data-urlencode "title=chore(skeleton): pin the CI template ref to ${TAG}" \
    --data-urlencode "description=Opened by the \`${TAG}\` tag pipeline (\`bump-skeleton-ref\`). Needs the owner's sign-off like any MR." \
    --data-urlencode "remove_source_branch=true" \
    "${API}/projects/${ID}/merge_requests" >/dev/null
echo "bump-skeleton-ref: opened an MR bumping the skeleton ref to ${TAG}."
