#!/usr/bin/env bash
# deploy.sh <env> — apply every app's manifests for one environment and wait for the
# rollout. Shipped by uBixCore; apps use this copy, they do not keep their own.
#
# <env> is the branch slug the manifests are named after: dev, staging, main (prod),
# or sandbox (microk8s on the runner). For each app/<App>/ in the project:
#
#   <env>-deploy.yaml      applied with {{DATE_STAMP}} replaced, so an unchanged mutable
#                          image tag still rolls; then `rollout status` is awaited
#   <env>-service.yaml     applied if present
#   <env>-ingress.yaml     applied if present
#   <env>-worker*.yaml     each applied with {{DATE_STAMP}} replaced, like the deploy; then
#                          `rollout status` is awaited for each
#   <env>-cronjob*.yaml    each applied (no rollout to wait for)
#
# An app directory with none of these is skipped. Secrets are never applied from here:
# pods read them from uBixVault at start.
#
# Settings (environment):
#   UBIX_PROJECT_DIR      the app repo (default CI_PROJECT_DIR, then the git top level)
#   UBIX_KUBECONFIGS      space-separated kubeconfig paths, each cluster gets every apply
#                         (default /home/gitlab-runner/.kube/devstageconfig)
#   UBIX_ROLLOUT_TIMEOUT  per-Deployment wait (default 300s); "0" skips the wait
#
# Posts the result to #ops through lib/notify.sh (best effort).
set -euo pipefail

ENVIRONMENT="${1:?usage: deploy.sh <dev|staging|main|sandbox>}"
UBIX_CI_BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_DIR="${UBIX_PROJECT_DIR:-${CI_PROJECT_DIR:-$(git rev-parse --show-toplevel)}}"
ROLLOUT_TIMEOUT="${UBIX_ROLLOUT_TIMEOUT:-300s}"
DATE_STAMP="$(date +%s)"

# shellcheck source=SCRIPTDIR/lib/notify.sh
. "$UBIX_CI_BIN/lib/notify.sh"
TMP="$(mktemp -d)"
deploy_notify() {
    local rc=$?
    rm -rf "$TMP"
    local sha="${CI_COMMIT_SHORT_SHA:-$(git -C "$PROJECT_DIR" rev-parse --short HEAD 2>/dev/null)}"
    local who="${GITLAB_USER_NAME:-${USER:-unknown}}"
    local link="${CI_PIPELINE_URL:-}"
    if [ "$rc" -eq 0 ]; then
        discord ops 3066993 "Deployed ${ENVIRONMENT}" "\`${sha}\` ${CI_COMMIT_TITLE:-} — by ${who}${link:+\nPipeline: ${link}}"
    else
        discord ops 15158332 "Deploy FAILED on ${ENVIRONMENT}" "\`${sha}\` — by ${who} (exit ${rc})${link:+\nPipeline: ${link}}"
    fi
}
trap deploy_notify EXIT

# One kubectl command line per target cluster.
TARGETS=()
if [ "$ENVIRONMENT" = sandbox ]; then
    mkdir -p "$PROJECT_DIR/log"; chmod 777 "$PROJECT_DIR/log"
    TARGETS=("microk8s kubectl")
else
    read -r -a CONFIGS <<<"${UBIX_KUBECONFIGS:-/home/gitlab-runner/.kube/devstageconfig}"
    for c in "${CONFIGS[@]}"; do TARGETS+=("kubectl --kubeconfig $c"); done
fi

kube() { # kube <target> <args...>
    local target="$1"; shift
    # shellcheck disable=SC2086  # the target is a command line on purpose
    $target "$@"
}

echo "Deploying to environment: $ENVIRONMENT"
APPLIED=0

for dir in "$PROJECT_DIR"/app/*/; do
    [ -d "$dir" ] || continue
    app="$(basename "$dir")"
    deploy_file="$dir${ENVIRONMENT}-deploy.yaml"
    cronjobs=("$dir${ENVIRONMENT}"-cronjob*.yaml)
    workers=("$dir${ENVIRONMENT}"-worker*.yaml)
    [ -f "$deploy_file" ] || [ -f "${cronjobs[0]}" ] || [ -f "${workers[0]}" ] || continue

    for target in "${TARGETS[@]}"; do
        if [ -f "$deploy_file" ]; then
            rendered="$TMP/$app-deploy.yaml"
            sed "s/{{DATE_STAMP}}/${DATE_STAMP}/g" "$deploy_file" > "$rendered"
            echo "[$app] apply $(basename "$deploy_file")"
            kube "$target" apply -f "$rendered"
            for kind in service ingress; do
                f="$dir${ENVIRONMENT}-${kind}.yaml"
                if [ -f "$f" ]; then echo "[$app] apply $(basename "$f")"; kube "$target" apply -f "$f"; fi
            done
        fi
        for f in "${workers[@]}"; do
            [ -f "$f" ] || continue
            rendered="$TMP/$app-$(basename "$f")"
            sed "s/{{DATE_STAMP}}/${DATE_STAMP}/g" "$f" > "$rendered"
            echo "[$app] apply $(basename "$f")"
            kube "$target" apply -f "$rendered"
        done
        for f in "${cronjobs[@]}"; do
            if [ -f "$f" ]; then echo "[$app] apply $(basename "$f")"; kube "$target" apply -f "$f"; fi
        done

        # `apply` succeeding says nothing about the pod: without this a crashlooping pod
        # (a missing Vault key, a bad image) counts as a green deploy and promotion runs on it.
        if [ -f "$deploy_file" ] && [ "$ROLLOUT_TIMEOUT" != 0 ]; then
            echo "[$app] waiting for rollout (timeout $ROLLOUT_TIMEOUT)"
            if ! kube "$target" rollout status -f "$TMP/$app-deploy.yaml" --timeout="$ROLLOUT_TIMEOUT"; then
                echo "[$app] rollout did not complete on '$target'" >&2
                kube "$target" get -f "$TMP/$app-deploy.yaml" -o wide >&2 || true
                exit 1
            fi
        fi
        if [ "$ROLLOUT_TIMEOUT" != 0 ]; then
            for f in "${workers[@]}"; do
                [ -f "$f" ] || continue
                rendered="$TMP/$app-$(basename "$f")"
                echo "[$app] waiting for $(basename "$f") rollout (timeout $ROLLOUT_TIMEOUT)"
                if ! kube "$target" rollout status -f "$rendered" --timeout="$ROLLOUT_TIMEOUT"; then
                    echo "[$app] $(basename "$f") rollout did not complete on '$target'" >&2
                    kube "$target" get -f "$rendered" -o wide >&2 || true
                    exit 1
                fi
            done
        fi
    done
    APPLIED=$((APPLIED + 1))
    echo "[$app] deployed"
done

if [ "$APPLIED" -eq 0 ]; then
    echo "No app/*/${ENVIRONMENT}-deploy.yaml, ${ENVIRONMENT}-worker*.yaml or ${ENVIRONMENT}-cronjob*.yaml found: nothing deployed." >&2
    exit 1
fi
echo "Deployed ${APPLIED} app(s) to ${ENVIRONMENT}."
