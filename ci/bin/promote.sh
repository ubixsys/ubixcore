#!/usr/bin/env bash
# promote.sh <target-branch> — fast-forward the next branch to this pipeline's commit
# (dev -> staging, staging -> main), run after a green deploy of the source branch.
# Shipped by uBixCore; apps use this copy, they do not keep their own.
#
#   - Destructive-migration halt: a `Destructive:` marker in the sql/migrations being
#     promoted halts the automatic flow (exit 0, #alerts told); a person runs the
#     promote-to-<target>-destructive manual job, which sets PROMOTE_ACK_DESTRUCTIVE=1.
#   - Fails closed: if the target's tip cannot be resolved, or the migration diff cannot
#     be taken, nothing is promoted. A flaky fetch must not switch off the halt above.
#   - Backwards guard: if the target already contains the commit, nothing to do.
#   - Fast-forward only. Never forces. A non-ff means the target was written to
#     directly; reconcile by hand.
#
# Needs GITLAB_PROMOTE_TOKEN: a project access token (Maintainer, write_repository) that
# may push to the protected target branches. UBIX_PROJECT_DIR (default CI_PROJECT_DIR,
# then the git top level) is the app repo.
set -u

TARGET="${1:?usage: promote.sh <target-branch>}"
UBIX_CI_BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_DIR="${UBIX_PROJECT_DIR:-${CI_PROJECT_DIR:-$(git rev-parse --show-toplevel)}}"
# shellcheck source=SCRIPTDIR/lib/notify.sh
. "$UBIX_CI_BIN/lib/notify.sh"

SHA="${CI_COMMIT_SHA:-$(git -C "$PROJECT_DIR" rev-parse HEAD)}"
SHORT="${CI_COMMIT_SHORT_SHA:-${SHA:0:8}}"
SRC="${CI_COMMIT_REF_NAME:-$(git -C "$PROJECT_DIR" rev-parse --abbrev-ref HEAD)}"
WHO="${GITLAB_USER_LOGIN:-${GITLAB_USER_NAME:-unknown}}"
LINK="${CI_PIPELINE_URL:-}"

git -C "$PROJECT_DIR" fetch -q origin "$TARGET" 2>/dev/null || true
# `--verify`: plain `rev-parse FETCH_HEAD` echoes its argument when it cannot resolve
# it, so a failed fetch would look like a SHA and the checks below could not see it.
TARGET_SHA="$(git -C "$PROJECT_DIR" rev-parse -q --verify 'FETCH_HEAD^{commit}' 2>/dev/null || echo '')"
if [ -z "$TARGET_SHA" ]; then
    echo "Could not resolve origin/${TARGET}: refusing to promote without knowing which migrations are in the batch."
    discord alerts 15158332 "❌ ${SRC}→${TARGET} promotion refused" "Could not resolve \`origin/${TARGET}\` for \`${SHORT}\`, so the destructive-migration check could not run. Nothing was promoted.${LINK:+\nPipeline: ${LINK}}"
    exit 1
fi

# Destructive-migration carve-out (docs/standards/migrations.md).
if ! MIGRATION_DIFF=$(git -C "$PROJECT_DIR" diff "${TARGET_SHA}..${SHA}" -- 'sql/migrations/*.sql' 2>&1); then
    echo "Could not diff sql/migrations ${TARGET_SHA}..${SHA}: ${MIGRATION_DIFF}"
    discord alerts 15158332 "❌ ${SRC}→${TARGET} promotion refused" "Could not diff \`sql/migrations\` for \`${SHORT}\`, so the destructive-migration check could not run. Nothing was promoted.${LINK:+\nPipeline: ${LINK}}"
    exit 1
fi
DESTRUCTIVE=$(printf '%s\n' "$MIGRATION_DIFF" | grep '^[+].*Destructive:' || true)
if [ -n "$DESTRUCTIVE" ]; then
    if [ "${PROMOTE_ACK_DESTRUCTIVE:-0}" = "1" ]; then
        echo "Destructive migration in batch, ACKNOWLEDGED by $WHO. Proceeding."
        discord ops 15105570 "⚠️ ${SRC}→${TARGET}: promoting WITH a destructive migration" "\`${SHORT}\` acknowledged by ${WHO}${LINK:+\nPipeline: ${LINK}}"
    else
        echo "${SRC}->${TARGET} auto-promotion HALTED: destructive migration in the batch. Review, then run the promote-to-${TARGET}-destructive manual job."
        discord alerts 15105570 "⚠️ ${SRC}→${TARGET} promotion halted" "A \`Destructive:\` migration is in \`${SHORT}\`. Review it, then run **promote-to-${TARGET}-destructive** on the pipeline.${LINK:+\nPipeline: ${LINK}}"
        exit 0
    fi
fi

if git -C "$PROJECT_DIR" merge-base --is-ancestor "$SHA" "$TARGET_SHA" 2>/dev/null; then
    echo "$TARGET already contains $SHORT: nothing to promote."; exit 0
fi

TOKEN="${GITLAB_PROMOTE_TOKEN:-}"
if [ -z "$TOKEN" ]; then
    echo "GITLAB_PROMOTE_TOKEN not set: cannot promote."
    discord alerts 15158332 "❌ ${SRC}→${TARGET} promotion failed" "GITLAB_PROMOTE_TOKEN is not configured (\`${SHORT}\`)."
    exit 1
fi
URL="https://oauth2:${TOKEN}@${CI_SERVER_HOST:?CI_SERVER_HOST}/${CI_PROJECT_PATH:?CI_PROJECT_PATH}.git"
echo "Promoting ${SRC}@${SHORT} -> ${TARGET} (fast-forward only)"
if OUT=$(git -C "$PROJECT_DIR" push "$URL" "${SHA}:refs/heads/${TARGET}" 2>&1); then
    echo "$OUT"; echo "Promoted to $TARGET."
    discord ops 3066993 "🚀 Promoted ${SRC} → ${TARGET}" "\`${SHORT}\` ${CI_COMMIT_TITLE:-}: the ${TARGET} pipeline now builds and deploys.${LINK:+\nPipeline: ${LINK}}"
else
    echo "$OUT"
    case "$OUT" in
        *"401"*|*"Authentication failed"*|*"Access denied"*) reason="token auth failed: refresh GITLAB_PROMOTE_TOKEN" ;;
        *"protected branch"*|*"pre-receive hook declined"*)  reason="branch protection: allow the token to push to ${TARGET}" ;;
        *"non-fast-forward"*|*"[rejected]"*|*"fetch first"*)  reason="${TARGET} diverged from the promotion flow: reconcile by hand (never forced)" ;;
        *) reason="see job log" ;;
    esac
    echo "push to $TARGET FAILED: $reason"
    discord alerts 15158332 "❌ ${SRC}→${TARGET} promotion failed" "${reason} (\`${SHORT}\`)${LINK:+\nPipeline: ${LINK}}"
    exit 1
fi
