#!/usr/bin/env bash
# X-Ray — HTTP / flow tests.
# Exercises front-end rendering of the nested structures plus the
# wrapping & access-control gates, against the running dev site.
#
#   bash plugins/x-ray/tests/http.sh

BASE="${BASE:-http://localhost:8080}"
pass=0; fail=0; failures=()

grn(){ printf '\033[32m%s\033[0m\n' "$1"; }
red(){ printf '\033[31m%s\033[0m\n' "$1"; }
ylw(){ printf '\033[33m%s\033[0m\n' "$1"; }

# assert_contains <name> <url> <needle>
assert_contains(){
  local name="$1" url="$2" needle="$3"
  if curl -s "$url" | grep -qF -- "$needle"; then
    grn "  ✓ $name"; pass=$((pass+1))
  else
    red "  ✗ $name (missing: $needle)"; fail=$((fail+1)); failures+=("$name")
  fi
}

# assert_absent <name> <url> <needle>
assert_absent(){
  local name="$1" url="$2" needle="$3"
  if curl -s "$url" | grep -qF -- "$needle"; then
    red "  ✗ $name (unexpectedly present: $needle)"; fail=$((fail+1)); failures+=("$name")
  else
    grn "  ✓ $name"; pass=$((pass+1))
  fi
}

# assert_status <name> <url> <regex-of-acceptable-codes>
assert_status(){
  local name="$1" url="$2" re="$3"
  local code; code=$(curl -s -o /dev/null -w '%{http_code}' "$url")
  if [[ "$code" =~ $re ]]; then
    grn "  ✓ $name (HTTP $code)"; pass=$((pass+1))
  else
    red "  ✗ $name (HTTP $code, expected $re)"; fail=$((fail+1)); failures+=("$name")
  fi
}

ylw "▸ Front-end pages render"
assert_status   "neon-void 200"        "$BASE/games/neon-void"        '^200$'
assert_status   "dragon-realms 200"    "$BASE/games/dragon-realms-vi" '^200$'
assert_status   "speed-kings 200"      "$BASE/games/speed-kings-turbo" '^200$'

ylw "▸ 5-level distinct chain (Neon Void)"
assert_contains "renders level 1"      "$BASE/games/neon-void" "Continent (level 1 of 5)"
assert_contains "renders level 3"      "$BASE/games/neon-void" "Region (level 3 of 5)"
assert_contains "renders leaf"         "$BASE/games/neon-void" "rock bottom"
assert_contains "distinct names shown" "$BASE/games/neon-void" "🪆 Province"

ylw "▸ Accordion (Dragon Realms VI)"
assert_contains "accordion title"      "$BASE/games/dragon-realms-vi" "Frequently Asked Questions"
assert_contains "nested panel content" "$BASE/games/dragon-realms-vi" "Two-player netrunning"

ylw "▸ Cars → Attributes → Details (Speed Kings)"
assert_contains "car name"             "$BASE/games/speed-kings-turbo" "Aurora GT-R"
assert_contains "attribute detail key" "$BASE/games/speed-kings-turbo" "Body Color"
assert_contains "detail value"         "$BASE/games/speed-kings-turbo" "Midnight Pearl"

ylw "▸ Wrapping & access control"
# No X-Ray annotations unless an admin activates with the param.
assert_absent   "no wrap without param"        "$BASE/games/neon-void"                "data-craft-component"
assert_absent   "no wrap for anon + param"     "$BASE/games/neon-void?xray=1" "data-craft-component"
assert_absent   "no client JS for anon"        "$BASE/games/neon-void?xray=1" "xray-client"

ylw "▸ API requires auth"
# The CP API must not return component/element data to an anonymous request.
assert_status   "api blocks anon"      "$BASE/admin/x-ray/api/component-props?cid=xr_deadbeef" '^(301|302|400|403)$'
assert_absent   "api leaks no props"   "$BASE/admin/x-ray/api/component-props?cid=xr_deadbeef" '"props"'
assert_status   "globals block anon"   "$BASE/admin/x-ray/api/global-sets" '^(301|302|400|403)$'

# ── Summary ─────────────────────────────────────────────────────────────────
echo
echo "════════════════════════════════════════════════════"
total=$((pass+fail))
if [[ $fail -eq 0 ]]; then
  grn "✅  ALL $total HTTP TESTS PASSED"
  exit 0
else
  red "❌  $fail of $total FAILED"
  for f in "${failures[@]}"; do echo "   • $f"; done
  exit 1
fi
