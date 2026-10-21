#!/usr/bin/env bash
# Copy ci/bin/require-approval.sh into the marked block of ci/require-approval.yml, so the
# include is self-contained (no fetch, no job-token allowlist, no ref to keep in step).
#   inline-require-approval.sh          rewrite the yml
#   inline-require-approval.sh --check  exit 1 when the yml is out of date (tests run this)
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
SRC=ci/bin/require-approval.sh YML=ci/require-approval.yml
BEGIN='# >>> inlined from ci/bin/require-approval.sh'; END='# <<< end inlined'
NEW="$(mktemp)"; trap 'rm -f "$NEW"' EXIT
{
  awk -v b="      ${BEGIN}" '{ print } $0 == b { exit }' "$YML"
  tail -n +2 "$SRC" | sed -E 's/^(.)/      \1/'
  awk -v e="      ${END}" '$0 == e { p = 1 } p' "$YML"
} > "$NEW"
if [ "${1:-}" = "--check" ]; then
  diff -q "$NEW" "$YML" >/dev/null || { echo "$YML is out of date: run ci/bin/inline-require-approval.sh"; exit 1; }
else
  cat "$NEW" > "$YML"
fi
