#!/usr/bin/env bash
# arm-merge.sh — arm merge-when-pipeline-succeeds on this pipeline's merge request, run by
# require-approval-mr after the owner's Approve or 👍 has been verified. Shipped by uBixCore.
#
# Approving then finishes the merge: the uBixOps webhook (or a manual retry) turns the
# sign-off job green, this arms the merge, and GitLab merges when the pipeline is green.
#
#   - Pinned to CI_COMMIT_SHA: a later push voids the arm, so only the commit that was
#     signed off can merge.
#   - Never merges what GitLab would not: "Pipelines must succeed", resolved threads and
#     the rest still decide.
#   - Soft: arming is a convenience on top of a sign-off that already passed, so every
#     problem is a message and exit 0. The person can still merge by hand.
#
# Needs APPROVAL_MERGE_TOKEN: a project or group access token, Developer, `api`, masked,
# not protected (MR branches are unprotected). The Reporter token that reads approvals
# cannot merge, and stays read-only. Uses CI_API_V4_URL, CI_PROJECT_ID,
# CI_MERGE_REQUEST_IID and CI_COMMIT_SHA from the MR pipeline.
set -u

if [ -z "${APPROVAL_MERGE_TOKEN:-}" ]; then
  echo "arm-merge: no APPROVAL_MERGE_TOKEN; merge not armed (merge by hand)"; exit 0
fi
if [ -z "${CI_MERGE_REQUEST_IID:-}" ]; then
  echo "arm-merge: not a merge request pipeline; nothing to arm"; exit 0
fi

BODY="$(mktemp)"; trap 'rm -f "$BODY"' EXIT
CODE="$(curl -sS -o "$BODY" -w '%{http_code}' -X PUT \
  -H "PRIVATE-TOKEN: $APPROVAL_MERGE_TOKEN" \
  --data-urlencode "sha=${CI_COMMIT_SHA:?}" \
  --data-urlencode "merge_when_pipeline_succeeds=true" \
  --data-urlencode "should_remove_source_branch=true" \
  "${CI_API_V4_URL:?}/projects/${CI_PROJECT_ID:?}/merge_requests/${CI_MERGE_REQUEST_IID}/merge")" || CODE=000

case "$CODE" in
  200) echo "arm-merge: !${CI_MERGE_REQUEST_IID} will merge when its pipeline succeeds (sha ${CI_COMMIT_SHA:0:8})" ;;
  405|406) echo "arm-merge: GitLab will not arm !${CI_MERGE_REQUEST_IID} yet (HTTP $CODE: not mergeable, or already merged); merge by hand" ;;
  409) echo "arm-merge: !${CI_MERGE_REQUEST_IID} has moved past ${CI_COMMIT_SHA:0:8} (HTTP 409); a newer pipeline owns the sign-off" ;;
  401|403) echo "arm-merge: APPROVAL_MERGE_TOKEN refused (HTTP $CODE); it needs Developer role and api scope" ;;
  *) echo "arm-merge: HTTP $CODE; merge not armed"; head -c 300 "$BODY"; echo ;;
esac
exit 0
