#!/usr/bin/env bash
# Run every X-Ray test suite (PHP + JS + HTTP) and aggregate results.
#
#   bash plugins/x-ray/tests/run-all.sh
#
# Assumes the dev stack is up (docker compose up) and run from the repo root.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT" || exit 2
rc=0

printf '\n\033[36m━━━ 1/3  PHP suite (in container) ━━━\033[0m\n'
docker compose exec -T web php craft x-ray/test 2>/dev/null || rc=1

printf '\n\033[36m━━━ 2/3  JS client suite (node) ━━━\033[0m\n'
node plugins/x-ray/tests/client.test.js || rc=1

printf '\n\033[36m━━━ 3/4  HTTP / flow suite (curl) ━━━\033[0m\n'
bash plugins/x-ray/tests/http.sh || rc=1

printf '\n\033[36m━━━ 4/4  Authenticated CP suite (curl) ━━━\033[0m\n'
bash plugins/x-ray/tests/cp.sh || rc=1

printf '\n════════════════════════════════════════════════════\n'
if [[ $rc -eq 0 ]]; then
  printf '\033[32m🎉  ALL SUITES PASSED\033[0m\n\n'
else
  printf '\033[31m💥  ONE OR MORE SUITES FAILED\033[0m\n\n'
fi
exit $rc
