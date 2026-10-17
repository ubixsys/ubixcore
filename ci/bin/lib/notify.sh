#!/usr/bin/env bash
#
# uBixVault reads and Discord notes for CI jobs and the ci/bin scripts. Shipped by
# uBixCore; apps use this copy (the template fetches it at the app's locked uBixCore
# version), they do not keep their own. Source it:
#
#   . "$UBIX_CI_BIN/lib/notify.sh"
#
# Vault: which Vault and read-only CI token serve a branch (vault_for_branch):
#   the prod branch (UBIX_PROD_BRANCH, default main; also master) -> prod
#   the staging branch (UBIX_STAGING_BRANCH, default staging)   -> UBIX_STAGING_VAULT
#                                                                  (prod, the default, or dev)
#   anything else                                                -> dev
# with UBIXVAULT_CI_TOKEN_DEV / UBIXVAULT_CI_TOKEN_PROD (masked GitLab variables).
# Secrets are read from secret/<VAULT_KV_PREFIX>/<path>; the prefix defaults to
# CI_PROJECT_NAME.
#
# Discord webhook for channel <name> (ops | alerts | reviews), first found wins:
#   1. env DISCORD_<NAME>_WEBHOOK_URL
#   2. secret/<prefix>/discord, key <name>_webhook_url
#   3. secret/ubixcore/discord, same key
# A missing webhook or a failed POST never affects the caller (returns 0).

UBIXVAULT_ADDR_DEV="${UBIXVAULT_ADDR_DEV:-https://vault.dev.ubixsys.com}"
UBIXVAULT_ADDR_PROD="${UBIXVAULT_ADDR_PROD:-https://vault.prod.ubixsys.com}"

# The secret prefix: VAULT_KV_PREFIX, else the project name.
ubix_vault_prefix() {
    printf '%s' "${VAULT_KV_PREFIX:-${CI_PROJECT_NAME:-app}}"
}

# Escape a string for embedding inside a JSON string value (no jq dependency).
json_escape() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\t'/ }"
    s="${s//$'\r'/}"
    printf '%s' "$s"
}

# vault_for_branch [ref] — sets VAULT_ADDR and VAULT_TOKEN for a branch (default:
# CI_COMMIT_REF_NAME, then the current git branch).
vault_for_branch() {
    local ref="${1:-${CI_COMMIT_REF_NAME:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null)}}"
    local tier=dev
    if [ "$ref" = "${UBIX_PROD_BRANCH:-main}" ] || [ "$ref" = master ]; then
        tier=prod
    elif [ "$ref" = "${UBIX_STAGING_BRANCH:-staging}" ]; then
        tier="${UBIX_STAGING_VAULT:-prod}"
    fi
    if [ "$tier" = prod ]; then
        VAULT_ADDR="$UBIXVAULT_ADDR_PROD"; VAULT_TOKEN="${UBIXVAULT_CI_TOKEN_PROD:-}"
    else
        VAULT_ADDR="$UBIXVAULT_ADDR_DEV";  VAULT_TOKEN="${UBIXVAULT_CI_TOKEN_DEV:-}"
    fi
}

# vault_read_key <full-path-under-secret> <key> — prints the value, or nothing.
vault_read_key() {
    local path="$1" key="$2"
    vault_for_branch
    [ -n "${VAULT_TOKEN:-}" ] || return 0
    curl -sS -m 10 -H "X-Vault-Token: $VAULT_TOKEN" "$VAULT_ADDR/v1/secret/data/${path}" 2>/dev/null \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["data"].get(sys.argv[1], ""))' "$key" 2>/dev/null \
        || true
}

# vault_kv <path> <key> — prints secret/<prefix>/<path>.<key> via the branch's
# read-only CI token, or nothing.
vault_kv() {
    vault_read_key "$(ubix_vault_prefix)/$1" "$2"
}

# discord_webhook <ops|alerts|reviews> — prints the URL or nothing.
discord_webhook() {
    local name="$1" upper url path
    upper="$(printf '%s' "$name" | tr '[:lower:]' '[:upper:]')"
    url="$(printenv "DISCORD_${upper}_WEBHOOK_URL" 2>/dev/null || true)"
    if [ -n "$url" ]; then printf '%s' "$url"; return 0; fi
    for path in "$(ubix_vault_prefix)/discord" ubixcore/discord; do
        url="$(vault_read_key "$path" "${name}_webhook_url")"
        if [ -n "$url" ]; then printf '%s' "$url"; return 0; fi
    done
}

# discord <ops|alerts|reviews> <color-int> <title> <text>
# Posts one embed. color: 3066993 green · 15158332 red · 15105570 orange · 3447003 blue.
discord() {
    local channel="$1" color="$2" title="$3" text="$4" url payload sender
    url="$(discord_webhook "$channel")"
    if [ -z "$url" ]; then
        vault_for_branch
        echo "No Discord webhook for #${channel}: skipping notify (tried \$DISCORD_$(printf '%s' "$channel" | tr '[:lower:]' '[:upper:]')_WEBHOOK_URL, then ${channel}_webhook_url at secret/$(ubix_vault_prefix)/discord and secret/ubixcore/discord on ${VAULT_ADDR}; CI token $([ -n "${VAULT_TOKEN:-}" ] && echo present || echo MISSING))"
        return 0
    fi
    sender="${UBIX_NOTIFY_SENDER:-${CI_PROJECT_NAME:-uBix} CI}"
    payload="{\"username\":\"$(json_escape "$sender")\",\"embeds\":[{\"title\":\"$(json_escape "$title")\",\"description\":\"$(json_escape "$text")\",\"color\":${color}}]}"
    curl -sS -m 10 -X POST -H 'Content-Type: application/json' --data "$payload" "$url" >/dev/null 2>&1 \
        && echo "Posted to #${channel}" \
        || echo "Discord notify failed for #${channel} (ignored)"
    return 0
}
