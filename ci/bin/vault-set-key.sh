#!/usr/bin/env bash
#
# Set ONE key inside a uBixVault KV v2 secret, without disturbing the others.
#
# Shipped by uBixCore (ci/bin/vault-set-key.sh); apps use this copy. In an app:
#   vendor/ubixsys/ubixcore/ci/bin/vault-set-key.sh <vault-addr> <kv-path> <KEY_NAME>
#
# Why this exists: a KV v2 write replaces the whole secret. An app secret such as
# `<app>/<env>/api` holds many unrelated keys (payment, OAuth, encryption keys), so a
# blind write silently destroys whatever it does not mention. This reads the secret,
# merges one key, and writes it back with a compare-and-swap so a concurrent write fails
# loudly instead of one of them disappearing.
#
# It also exists so a secret can reach Vault WITHOUT passing through an agent
# session or a chat transcript. The operator runs this; the value is typed at a
# prompt, never in argv (where `ps` would show it) and never in shell history.
# Nothing prints the value back.
#
# Usage:
#   bin/vault-set-key.sh <vault-addr> <kv-path> <KEY_NAME> [keychain-token-service]
#   bin/vault-set-key.sh <vault-addr> <kv-path> <KEY_NAME> --remove [keychain-token-service]
#   bin/vault-set-key.sh <vault-addr> <kv-path> <key_name> --any-case [keychain-token-service]
#
# --any-case allows a lower-case key. UPPER_SNAKE_CASE is the default because the
# app resolver hydrates a secret's keys into environment variables and skips any
# that are not UPPER_SNAKE_CASE -- a lower-case key there is written, reported as
# written, and then silently ignored by every pod. Secrets read by an explicit key
# name instead (secret/<app>/db-*, whose keys are `host`, `port`, `write_username`
# and so on, read by vault_kv in bin/lib/notify.sh) are not hydrated and are
# conventionally lower case.
#
# --remove deletes just that key, leaving the rest of the secret alone. Same
# read-merge-write with compare-and-swap as a set, because removing a key by
# rewriting the secret by hand is the same way the others get lost.
#
# Examples:
#   vault-set-key.sh https://vault.prod.ubixsys.com <app>/prod/api STRIPE_SECRET_KEY
#   vault-set-key.sh https://vault.dev.ubixsys.com  <app>/dev/api  MAILER_DSN ubixvault-dev-admin
#   vault-set-key.sh https://vault.dev.ubixsys.com  <app>/db-dev host --any-case ubixvault-dev-admin
#
# The value may also be piped in (`< file`), so a password kept in a mode-600 file
# never reaches argv, a screen or a transcript.
#
# The Vault token is read from the macOS Keychain (default service
# `ubixvault-prod-admin`, or pass another as the 4th argument) so it is not in
# argv or the environment either.

set -euo pipefail

# Keep the original argument list for the diagnostic below: the parsing loop
# shifts, and a usage message that reports the shifted list is worse than none.
ORIG_ARGS=("$@")

ADDR="${1:-}"
PATH_KV="${2:-}"
KEY="${3:-}"
REMOVE="no"
ANY_CASE="no"
TOKEN_SERVICE=""

# --remove may appear anywhere after the key name, so neither order surprises.
shift 3 2>/dev/null || true
for arg in "$@"; do
    case "$arg" in
        --remove)   REMOVE="yes" ;;
        --any-case) ANY_CASE="yes" ;;
        *)        TOKEN_SERVICE="$arg" ;;
    esac
done
TOKEN_SERVICE="${TOKEN_SERVICE:-ubixvault-prod-admin}"

usage() {
    echo "usage: $0 <vault-addr> <kv-path> <KEY_NAME> [--remove] [--any-case] [keychain-token-service]" >&2
    echo >&2
    echo "  e.g. $0 https://vault.prod.ubixsys.com <app>/prod/api MAILER_DSN" >&2
    echo >&2
    echo "received ${#ORIG_ARGS[@]} argument(s):" >&2
    local i=1
    for arg in "${ORIG_ARGS[@]}"; do
        printf '  %d: [%s]\n' "$i" "$arg" >&2
        i=$((i + 1))
    done
}

if [ -z "$ADDR" ] || [ -z "$PATH_KV" ] || [ -z "$KEY" ]; then
    usage "$@"
    exit 64
fi

# Trim surrounding whitespace before validating. A copied argument routinely
# arrives with a trailing space or carriage return, and rejecting that as a bad
# key name sends the operator hunting for a problem in the name itself.
KEY="$(printf '%s' "$KEY" | tr -d '[:space:]')"

# UPPER_SNAKE_CASE, matching VaultCredentialResolverService::resolveAppSecrets()
# exactly: it hydrates only keys matching ^[A-Z][A-Z0-9_]*$ and skips the rest.
# A lowercase key would be written successfully and then silently ignored by every
# pod -- a worse outcome than refusing it here, because nothing would look wrong.
if [ "$ANY_CASE" = "yes" ]; then
    # Still constrained: a key name has to survive being interpolated into a shell
    # command and a JSON document.
    case "$KEY" in
        *[!A-Za-z0-9_]*)
            echo "error: key name [$KEY] must be letters, numbers and underscores only." >&2
            usage "$@"
            exit 64
            ;;
    esac
else
case "$KEY" in
    [A-Z]) ;;
    [A-Z]*[!A-Z0-9_]*)
        echo "error: key name [$KEY] is not UPPER_SNAKE_CASE." >&2
        echo "       Vault only hydrates keys matching ^[A-Z][A-Z0-9_]*\$ -- anything else is" >&2
        echo "       written and then silently ignored by the app. Did the arguments shift?" >&2
        usage "$@"
        exit 64
        ;;
    [A-Z]*) ;;
    *)
        echo "error: key name [$KEY] must start with an uppercase letter." >&2
        echo "       Vault only hydrates keys matching ^[A-Z][A-Z0-9_]*\$, so a lower-case" >&2
        echo "       key would be written and then ignored by every pod." >&2
        echo "       For a secret read by explicit key name instead -- secret/<app>/db-*," >&2
        echo "       whose keys are lower case -- pass --any-case." >&2
        usage "$@"
        exit 64
        ;;
esac
fi

# VAULT_* and MYSQL_* are the resolver's own namespaces and it refuses them, so a
# write here would look like it worked and change nothing.
case "$KEY" in
    VAULT_*|MYSQL_*)
        echo "error: [$KEY] is in a namespace the resolver refuses to hydrate." >&2
        exit 64
        ;;
esac

TOKEN="$(security find-generic-password -s "$TOKEN_SERVICE" -w 2>/dev/null || true)"
if [ -z "$TOKEN" ]; then
    echo "error: no Vault token in the Keychain under service '$TOKEN_SERVICE'." >&2
    echo "       add it with: security add-generic-password -s $TOKEN_SERVICE -a \"\$USER\" -w" >&2
    exit 1
fi

# Read the current secret first. A 404 is fine -- a new path. Anything else is
# not, because writing over a secret we failed to read is how keys get lost.
HTTP_BODY="$(mktemp)"
trap 'rm -f "$HTTP_BODY"' EXIT
STATUS="$(curl -sS -o "$HTTP_BODY" -w '%{http_code}' \
    -H "X-Vault-Token: $TOKEN" \
    "$ADDR/v1/secret/data/$PATH_KV")"

case "$STATUS" in
    200) ;;
    404) echo "note: $PATH_KV does not exist yet -- it will be created." ;;
    *)
        echo "error: reading $PATH_KV returned HTTP $STATUS. Refusing to write." >&2
        exit 1
        ;;
esac

echo
echo "Secret : $PATH_KV"
echo "Key    : $KEY"

if [ "$REMOVE" = "yes" ]; then
    if [ "$STATUS" = "404" ]; then
        echo "error: $PATH_KV does not exist, so there is nothing to remove." >&2
        exit 1
    fi
    echo "Action : REMOVE this key (the rest of the secret is left alone)"
    VALUE=""
else
printf 'Value  : '
# -s: no echo. -r: no backslash mangling, which matters for a DSN or a key with
# escapes in it. Typed here so it never lands in argv or shell history.
IFS= read -rs VALUE
echo
if [ -z "$VALUE" ]; then
    echo "error: empty value, nothing written." >&2
    exit 64
fi
fi

# Merge and write in one python pass: it builds the request body from the read
# response, so every other key is carried over verbatim, and sets cas to the
# version just read so a concurrent write fails rather than silently winning.
# The value arrives via the environment, not argv.
VALUE="$VALUE" KEY="$KEY" REMOVE="$REMOVE" python3 - "$HTTP_BODY" "$STATUS" > "$HTTP_BODY.req" <<'PY'
import json
import os
import sys

body_path, status = sys.argv[1], sys.argv[2]

if status == '200':
    with open(body_path) as handle:
        payload = json.load(handle)
    data = payload['data']['data']
    version = payload['data']['metadata']['version']
else:
    data, version = {}, 0

key = os.environ['KEY']

if os.environ.get('REMOVE') == 'yes':
    if key not in data:
        # Better to say so than to write an identical secret and report success.
        sys.stderr.write('error: %s is not in this secret. Nothing written.\n' % key)
        sys.exit(3)
    del data[key]
else:
    data[key] = os.environ['VALUE']

json.dump({'data': data, 'options': {'cas': version}}, sys.stdout)
PY

STATUS="$(curl -sS -o "$HTTP_BODY" -w '%{http_code}' \
    -X POST \
    -H "X-Vault-Token: $TOKEN" \
    -H 'Content-Type: application/json' \
    --data-binary "@$HTTP_BODY.req" \
    "$ADDR/v1/secret/data/$PATH_KV")"
rm -f "$HTTP_BODY.req"
unset VALUE

if [ "$STATUS" != "200" ]; then
    echo "error: write returned HTTP $STATUS" >&2
    # A 400 here is usually the compare-and-swap refusing: somebody wrote to this
    # path between the read and the write. Re-run rather than forcing.
    sed -e 's/^/       /' "$HTTP_BODY" >&2 || true
    exit 1
fi

# Read back and report NAMES and LENGTHS only -- enough to see the key landed and
# that nothing else vanished, without putting any value on a screen or in a log.
curl -sS -H "X-Vault-Token: $TOKEN" "$ADDR/v1/secret/data/$PATH_KV" \
    | python3 -c '
import json
import sys

payload = json.load(sys.stdin)
print()
print("Written. Secret now holds (names and lengths only):")
for name, value in sorted(payload["data"]["data"].items()):
    print(f"  {name:<28} {len(str(value))} chars")
print()
print("version", payload["data"]["metadata"]["version"])
'
