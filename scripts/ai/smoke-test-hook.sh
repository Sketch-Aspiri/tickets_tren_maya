#!/usr/bin/env bash
# Smoke-test scripts/ai/validate-bash.sh without embedding deny patterns in an
# outer agent shell command line (PreToolUse matches the full command string).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HOOK="$ROOT/scripts/ai/validate-bash.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

run_case() {
  local name="$1" payload="$2" expect_code="$3"
  printf '%s' "$payload" >"$TMP/in.json"
  set +e
  out="$(bash "$HOOK" <"$TMP/in.json" 2>&1)"
  code=$?
  set -e
  if [[ "$code" -ne "$expect_code" ]]; then
    echo "FAIL $name: expected exit $expect_code got $code"
    echo "$out"
    exit 1
  fi
  echo "OK $name (exit $code)"
}

# Build payloads without writing forbidden tokens as contiguous literals in
# ways that confuse humans reading this file — still fine inside this script
# when executed directly (hook only sees this script's contents if the agent
# shells `bash smoke-test-hook.sh`, which does NOT contain `git add .env` as
# the primary command).

ENV_FILE=".env"
run_case cursor_deny_env "$(printf '{"command":"git add %s"}' "$ENV_FILE")" 2
run_case claude_deny_env "$(printf '{"tool_input":{"command":"git add %s"}}' "$ENV_FILE")" 2
run_case allow_status '{"command":"git status"}' 0
run_case allow_example '{"command":"git add .env.example"}' 0
run_case deny_fw "$(printf '{"command":"%s"}' "ufw disable")" 2
run_case ask_fresh "$(printf '{"command":"%s"}' "php artisan migrate:fresh")" 2

echo "All hook smoke tests passed."
