#!/usr/bin/env bash
# X-Ray — authenticated Control-Panel tests.
#
# Logs into the CP and POSTs the REAL plugin-settings form (the path that the
# colorField "#"-less hex bug slipped through), then exercises lazy props
# end-to-end as an admin. Also asserts the anonymous-rejection gates.
#
# Provide a throwaway admin's credentials via env to run the authenticated part:
#   CI_ADMIN_USER=admin CI_ADMIN_PASS='…' bash plugins/x-ray/tests/cp.sh
#
# Without creds it still runs the anon checks and SKIPS (not fails) the rest.

BASE="${BASE:-http://localhost:8080}"
DC="docker compose exec -T web php craft"
USER="${CI_ADMIN_USER:-}"
PASS="${CI_ADMIN_PASS:-}"
pass=0; fail=0; failures=()

grn(){ printf '\033[32m%s\033[0m\n' "$1"; }
red(){ printf '\033[31m%s\033[0m\n' "$1"; }
ylw(){ printf '\033[33m%s\033[0m\n' "$1"; }
okk(){ grn "  ✓ $1"; pass=$((pass+1)); }
bad(){ red "  ✗ $1"; fail=$((fail+1)); failures+=("$1"); }

# ── Always-on: anonymous requests must be rejected ──────────────────────────
ylw "▸ Anonymous rejection (no creds needed)"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
  --data-urlencode "action=plugins/save-plugin-settings" \
  --data-urlencode "pluginHandle=x-ray" \
  --data-urlencode "settings[accentColor]=00FF99" \
  -H 'Accept: application/json' "$BASE/index.php")
[[ "$code" =~ ^(400|403|302|301)$ ]] && okk "anon save rejected (HTTP $code)" || bad "anon save NOT rejected (HTTP $code)"

code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' \
  "$BASE/admin/x-ray/api/component-props?cid=xr_deadbeef")
[[ "$code" =~ ^(403|302|301)$ ]] && okk "anon component-props blocked (HTTP $code)" || bad "anon component-props NOT blocked (HTTP $code)"

# ── Authenticated flow ──────────────────────────────────────────────────────
if [[ -z "$USER" || -z "$PASS" ]]; then
  echo
  ylw "▸ Authenticated CP suite — SKIPPED (set CI_ADMIN_USER / CI_ADMIN_PASS)"
  echo "   A throwaway admin is fine. Example:"
  echo "   CI_ADMIN_USER=admin CI_ADMIN_PASS='secret' bash plugins/x-ray/tests/cp.sh"
else
  jar="$(mktemp)"
  get_csrf(){ curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' \
      "$BASE/index.php?action=users/session-info" \
      | grep -o '"csrfTokenValue":"[^"]*"' | sed 's/.*:"//; s/"$//'; }

  ylw "▸ Login"
  csrf="$(get_csrf)"
  curl -s -o /dev/null -b "$jar" -c "$jar" -H 'Accept: application/json' -H "X-CSRF-Token: $csrf" \
    --data-urlencode "CRAFT_CSRF_TOKEN=$csrf" \
    --data-urlencode "loginName=$USER" \
    --data-urlencode "password=$PASS" \
    "$BASE/index.php?action=users/login"
  # Confirm the session is authenticated (login response shape varies by version).
  if curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' "$BASE/index.php?action=users/session-info" | grep -q '"isGuest":false'; then
    okk "logged in as $USER"
  else
    bad "login failed (still a guest)"
  fi
  csrf="$(get_csrf)"   # token rotates after login

  # save-plugin-settings POST helper: echoes HTTP status
  save_setting(){ # <accentColorValue>
    curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" \
      --data-urlencode "action=plugins/save-plugin-settings" \
      --data-urlencode "pluginHandle=x-ray" \
      --data-urlencode "CRAFT_CSRF_TOKEN=$csrf" \
      --data-urlencode "settings[startUrl]=" \
      --data-urlencode "settings[activationParam]=xray" \
      --data-urlencode "settings[activeMode]=always" \
      --data-urlencode "settings[wrapPrefix]=_" \
      --data-urlencode "settings[showEditLinks]=1" \
      --data-urlencode "settings[showTooltip]=1" \
      --data-urlencode "settings[blockExternalNav]=1" \
      --data-urlencode "settings[persistSelection]=" \
      --data-urlencode "settings[tooltipLabel]=name" \
      --data-urlencode "settings[highlightStyle]=dotted" \
      --data-urlencode "settings[accentColor]=$1" \
      "$BASE/index.php"
  }

  ylw "▸ Save settings (the colorField regression)"
  code="$(save_setting '00FF99')"   # ← hash-less hex, exactly as colorField posts
  [[ "$code" =~ ^(200|301|302)$ ]] && okk "save POST accepted (HTTP $code)" || bad "save POST status $code"
  stored="$($DC x-ray/test/get accentColor 2>/dev/null | tr -d '\r\n ' | sed 's/.*level=warning.*//')"
  [[ "$stored" == "#00FF99" ]] && okk "hash-less hex persisted as #00FF99" || bad "persisted value was '$stored'"

  ylw "▸ Invalid value is rejected"
  save_setting 'zzzzzz' >/dev/null
  stored="$($DC x-ray/test/get accentColor 2>/dev/null | tr -d '\r\n ' | sed 's/.*level=warning.*//')"
  [[ "$stored" == "#00FF99" ]] && okk "invalid hex rejected (value unchanged)" || bad "invalid hex leaked: '$stored'"

  # restore default
  save_setting '7B61FF' >/dev/null

  ylw "▸ Lazy props end-to-end (admin, inspecting)"
  page="$(curl -s -b "$jar" -c "$jar" "$BASE/games/neon-void?xray=1")"
  echo "$page" | grep -q 'data-craft-component' && okk "components are annotated" || bad "no data-craft-component"
  echo "$page" | grep -q 'data-craft-id="xr_' && okk "carries lazy token (data-craft-id)" || bad "no data-craft-id token"
  echo "$page" | grep -q 'data-craft-props' && bad "inline props still present (should be lazy)" || okk "no inline data-craft-props (payload is lazy)"
  echo "$page" | grep -q 'xray-client' && okk "client asset injected for admin" || bad "client asset not injected"

  token="$(echo "$page" | grep -o 'data-craft-id="xr_[0-9a-f]*"' | head -1 | sed 's/.*"\(xr_[0-9a-f]*\)".*/\1/')"
  if [[ -n "$token" ]]; then
    resp="$(curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' \
      "$BASE/admin/x-ray/api/component-props?cid=$token")"
    echo "$resp" | grep -q '"props"' && okk "component-props API returns props for token" || bad "component-props missing props: $(echo "$resp" | head -c 120)"
  else
    bad "could not extract a token from the inspected page"
  fi

  rm -f "$jar"
fi

echo
echo "════════════════════════════════════════════════════"
total=$((pass+fail))
if [[ $fail -eq 0 ]]; then
  grn "✅  ALL $total CP TESTS PASSED"
  exit 0
else
  red "❌  $fail of $total FAILED"
  for f in "${failures[@]}"; do echo "   • $f"; done
  exit 1
fi
