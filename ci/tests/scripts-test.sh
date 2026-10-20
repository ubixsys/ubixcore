#!/usr/bin/env bash
# shellcheck disable=SC2016  # the bash -c bodies expand in the child, on purpose
# Offline tests for ci/bin: notify.sh's Vault selection and prefix, and deploy.sh
# against a fake kubectl. No network, no cluster. Run: bash ci/tests/scripts-test.sh
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
BIN="$PWD/ci/bin"
FAILS=0
check() { # check <name> <expected> <actual>
    if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected [$2], got [$3]"; FAILS=$((FAILS + 1)); fi
}

# --- notify.sh: vault_for_branch -------------------------------------------------
vault_of() { # vault_of <ref> [VAR=value ...] -> dev|prod
    env -i PATH="$PATH" UBIXVAULT_CI_TOKEN_DEV=d UBIXVAULT_CI_TOKEN_PROD=p "${@:2}" \
        bash -c '. "$1/lib/notify.sh"; vault_for_branch "$2"; [ "$VAULT_TOKEN" = p ] && echo prod || echo dev' _ "$BIN" "$1"
}
check "dev branch -> dev"                        dev  "$(vault_of dev)"
check "feature branch -> dev"                    dev  "$(vault_of feat/x)"
check "main -> prod"                             prod "$(vault_of main)"
check "master -> prod"                           prod "$(vault_of master)"
check "staging -> prod by default"               prod "$(vault_of staging)"
check "staging -> dev with UBIX_STAGING_VAULT"   dev  "$(vault_of staging UBIX_STAGING_VAULT=dev)"
check "custom prod branch"                       prod "$(vault_of release UBIX_PROD_BRANCH=release)"
check "custom staging branch"                    dev  "$(vault_of qa UBIX_STAGING_BRANCH=qa UBIX_STAGING_VAULT=dev)"

# --- notify.sh: secret prefix ----------------------------------------------------
prefix_of() { env -i PATH="$PATH" "$@" bash -c '. "$1/lib/notify.sh"; ubix_vault_prefix' _ "$BIN"; }
check "prefix from VAULT_KV_PREFIX"              kelpie     "$(prefix_of VAULT_KV_PREFIX=kelpie CI_PROJECT_NAME=ubixkelpie)"
check "prefix falls back to project name"        ubixkelpie "$(prefix_of CI_PROJECT_NAME=ubixkelpie)"

# --- deploy.sh against a fake kubectl ---------------------------------------------
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/fakebin" "$T/proj/app/WebApi" "$T/proj/app/Worker" "$T/proj/app/Docs"
cat > "$T/fakebin/kubectl" <<'EOF'
#!/usr/bin/env bash
echo "kubectl $*" >> "$KUBE_LOG"
case "$*" in *"rollout status"*) [ -z "${KUBE_FAIL_ROLLOUT:-}" ] ;; *) exit 0 ;; esac
EOF
chmod +x "$T/fakebin/kubectl"
printf 'image: x\nstamp: "{{DATE_STAMP}}"\n' > "$T/proj/app/WebApi/dev-deploy.yaml"
echo svc > "$T/proj/app/WebApi/dev-service.yaml"
echo ing > "$T/proj/app/WebApi/dev-ingress.yaml"
echo cron > "$T/proj/app/Worker/dev-cronjob-nightly.yaml"
echo other > "$T/proj/app/Docs/README.md"

run_deploy() {
    : > "$T/kube.log"
    env -i PATH="$T/fakebin:$PATH" HOME="$T" KUBE_LOG="$T/kube.log" UBIX_PROJECT_DIR="$T/proj" \
        UBIX_KUBECONFIGS="/k/one" "$@" bash "$BIN/deploy.sh" dev > "$T/out.log" 2>&1
}
run_deploy; RC=$?
check "deploy exits 0"                           0 "$RC"
check "deploy applies the rendered deployment"   1 "$(grep -c 'apply -f .*/WebApi-deploy.yaml' "$T/kube.log")"
check "deploy applies service and ingress"       2 "$(grep -cE 'apply -f .*/dev-(service|ingress).yaml' "$T/kube.log")"
check "deploy applies the cronjob"               1 "$(grep -c 'dev-cronjob-nightly.yaml' "$T/kube.log")"
check "deploy waits for the rollout"             1 "$(grep -c 'rollout status' "$T/kube.log")"
check "deploy uses the kubeconfig"               5 "$(grep -c -- '--kubeconfig /k/one' "$T/kube.log")"
check "deploy counts two apps"                   1 "$(grep -c 'Deployed 2 app(s) to dev' "$T/out.log")"

run_deploy KUBE_FAIL_ROLLOUT=1; RC=$?
check "failed rollout fails the deploy"          1 "$RC"

run_deploy UBIX_ROLLOUT_TIMEOUT=0
check "UBIX_ROLLOUT_TIMEOUT=0 skips the wait"    0 "$(grep -c 'rollout status' "$T/kube.log")"

rm "$T/proj/app/WebApi/"dev-* "$T/proj/app/Worker/"dev-*
run_deploy; RC=$?
check "nothing to deploy is an error"            1 "$RC"

# --- bump-skeleton-ref.sh --local -------------------------------------------------
B="$T/bump"; mkdir -p "$B/skeleton/docs" "$B/ci"
reset_bump() {
    printf 'include:\n  - project: ubixsys/ubixcore\n    ref: v0.47.0   # ubixcore-ref: pin\n' > "$B/skeleton/.gitlab-ci.yml"
    printf '```yaml\n    ref: v0.47.0\n```\n' > "$B/skeleton/docs/ci-setup.md"
    printf '      ref: v0.47.0\n' > "$B/ci/README.md"
}
bump() { UBIX_PROJECT_DIR="$B" bash "$BIN/bump-skeleton-ref.sh" --local "$1"; }
reset_bump
check "bump rewrites to a newer tag"             bumped "$(bump v0.48.0)"
check "bump updates the skeleton pipeline"       1 "$(grep -c 'ref: v0.48.0   # ubixcore-ref' "$B/skeleton/.gitlab-ci.yml")"
check "bump updates ci-setup.md"                 1 "$(grep -c 'ref: v0.48.0' "$B/skeleton/docs/ci-setup.md")"
check "bump updates ci/README.md"                1 "$(grep -c 'ref: v0.48.0' "$B/ci/README.md")"
check "bump of the current tag is a no-op"       current "$(bump v0.48.0)"
check "bump never downgrades"                    lower "$(bump v0.47.9)"
check "bump compares versions numerically"       bumped "$(bump v0.100.0)"
check "bump ignores a non-release tag"           1 "$(UBIX_PROJECT_DIR="$B" bash "$BIN/bump-skeleton-ref.sh" --local rc-1 | grep -c 'not a vN.N.N')"
check "bump without a token fails"               1 "$(env -i PATH="$PATH" CI_COMMIT_TAG=v9.0.0 bash "$BIN/bump-skeleton-ref.sh" >/dev/null 2>&1; echo $?)"

if [ "$FAILS" -ne 0 ]; then echo "$FAILS failed"; exit 1; fi
echo "all passed"
