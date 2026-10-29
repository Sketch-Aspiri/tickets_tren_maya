#!/usr/bin/env bash
# Pre-shell safety net for Claude Code (PreToolUse Bash) and Cursor (beforeShellExecution).
#
# Project: Sistema de Tickets (Tren Maya) — NOT the VPN-only dashboard.
# Non-negotiables from CLAUDE.md: MySQL localhost-only, SSH key-only, never
# commit .env, protect against destructive disk/git accidents. Access model
# here is public HTTPS (not Tailscale-only); still keep ufw/fail2ban intact.
#
# Auto-detects payload shape:
#   Cursor: { "command": "..." }  → { "permission": "allow|deny|ask", ... }
#   Claude: { "tool_input": { "command": "..." } } → Claude hookSpecificOutput
#
# Exit 0 = allow (or emit decision JSON). Exit 2 = deny/ask with JSON on stdout/stderr.
set -uo pipefail

input="$(cat)"

extract_command() {
  if command -v jq >/dev/null 2>&1; then
    printf '%s' "$input" | jq -r '
      if .tool_input.command != null and .tool_input.command != "" then .tool_input.command
      elif .command != null and .command != "" then .command
      else empty end
    ' 2>/dev/null
    return
  fi
  if command -v node >/dev/null 2>&1; then
    printf '%s' "$input" | node -e '
      let data = "";
      process.stdin.on("data", c => (data += c));
      process.stdin.on("end", () => {
        try {
          const json = JSON.parse(data);
          const cmd = (json.tool_input && json.tool_input.command) || json.command || "";
          process.stdout.write(cmd);
        } catch (e) {
          process.stdout.write("");
        }
      });
    ' 2>/dev/null
    return
  fi
  printf '%s' "$input" \
    | grep -o '"command"[[:space:]]*:[[:space:]]*"[^"]*"' \
    | head -n1 \
    | sed -E 's/^"command"[[:space:]]*:[[:space:]]*"//; s/"$//'
}

detect_flavor() {
  if printf '%s' "$input" | grep -q '"tool_input"'; then
    printf 'claude'
  else
    printf 'cursor'
  fi
}

FLAVOR="$(detect_flavor)"
command_str="$(extract_command || true)"

if [ -z "$command_str" ]; then
  exit 0
fi

emit() {
  local decision="$1" reason="$2"
  if [ "$FLAVOR" = "claude" ]; then
    if command -v jq >/dev/null 2>&1; then
      jq -n --arg d "$decision" --arg r "$reason" \
        '{hookSpecificOutput: {permissionDecision: $d}, systemMessage: $r}'
    elif command -v node >/dev/null 2>&1; then
      node -e '
        console.log(JSON.stringify({
          hookSpecificOutput: { permissionDecision: process.argv[1] },
          systemMessage: process.argv[2],
        }));
      ' "$decision" "$reason"
    else
      local escaped
      escaped=$(printf '%s' "$reason" | sed 's/\\/\\\\/g; s/"/\\"/g')
      printf '{"hookSpecificOutput":{"permissionDecision":"%s"},"systemMessage":"%s"}\n' "$decision" "$escaped"
    fi
  else
    # Cursor beforeShellExecution
    if command -v jq >/dev/null 2>&1; then
      jq -n --arg d "$decision" --arg r "$reason" \
        '{permission: $d, user_message: $r, agent_message: $r}'
    elif command -v node >/dev/null 2>&1; then
      node -e '
        console.log(JSON.stringify({
          permission: process.argv[1],
          user_message: process.argv[2],
          agent_message: process.argv[2],
        }));
      ' "$decision" "$reason"
    else
      local escaped
      escaped=$(printf '%s' "$reason" | sed 's/\\/\\\\/g; s/"/\\"/g')
      printf '{"permission":"%s","user_message":"%s","agent_message":"%s"}\n' "$decision" "$escaped" "$escaped"
    fi
  fi
}

deny() { emit "deny" "$1"; exit 2; }
ask()  { emit "ask"  "$1"; exit 2; }

c="$command_str"

# --- DENY -------------------------------------------------------------------

if [[ "$c" =~ rm[[:space:]]+(-[a-zA-Z]*[rR][a-zA-Z]*[fF][a-zA-Z]*|-[a-zA-Z]*[fF][a-zA-Z]*[rR][a-zA-Z]*)[[:space:]]+(/|/\*|~|\$HOME)([[:space:]]|$) ]]; then
  deny "Blocked: command deletes filesystem root or home. Not a tickets-dev action."
fi

if [[ "$c" == *"mkfs"* ]] || [[ "$c" == *"dd if="* && "$c" == *"of=/dev/"* ]] || [[ "$c" == *"> /dev/sd"* ]]; then
  deny "Blocked: disk-level destructive operation (mkfs/dd/raw device write)."
fi

if [[ "$c" == *"bind-address"* ]] && [[ "$c" == *"0.0.0.0"* ]]; then
  deny "Blocked: MySQL must stay on 127.0.0.1 (CLAUDE.md non-negotiable)."
fi

if [[ "$c" == *"ufw"* ]] && [[ "$c" == *"3306"* ]] && [[ "$c" == *"allow"* ]]; then
  deny "Blocked: opening MySQL port 3306 on the firewall."
fi

if [[ "$c" == *"PasswordAuthentication"* ]] && [[ "$c" == *"yes"* ]]; then
  deny "Blocked: SSH must remain key-only (PasswordAuthentication no)."
fi

if [[ "$c" == *"ufw disable"* ]] || [[ "$c" == *"ufw --force disable"* ]]; then
  deny "Blocked: disabling ufw removes the host firewall required by CLAUDE.md."
fi

# Only when the primary command is `git add` (avoids false positives from
# echo/tests that merely mention the string "git add .env").
trimmed_c="$(printf '%s' "$c" | sed 's/^[[:space:]]*//')"
if [[ "$trimmed_c" =~ ^git[[:space:]]+add([[:space:]]|$) ]] \
  && printf '%s' "$trimmed_c" | grep -qE '(^|[[:space:]])(\.?\.?/)?\.env([[:space:]]|$)' \
  && ! printf '%s' "$trimmed_c" | grep -qE '(^|[[:space:]])(\.?\.?/)?\.env\.example([[:space:]]|$)'; then
  deny "Blocked: staging .env. Secrets must never enter the repository."
fi

if [[ "$c" =~ curl.*\|[[:space:]]*(bash|sh) ]] || [[ "$c" =~ wget.*\|[[:space:]]*(bash|sh) ]]; then
  deny "Blocked: piping a remote download into a shell. Download and verify first."
fi

# --- ASK --------------------------------------------------------------------

if [[ "$c" =~ git[[:space:]]+push.*(--force|-f)([[:space:]]|$) ]]; then
  if [[ "$c" == *"main"* ]] || [[ "$c" == *"master"* ]]; then
    ask "Force-push to main/master detected. Confirm this is intentional."
  fi
  ask "Force-push detected. Confirm it will not discard remote work."
fi

if [[ "$c" =~ git[[:space:]]+reset[[:space:]]+--hard ]] || [[ "$c" =~ git[[:space:]]+clean[[:space:]]+-[a-zA-Z]*f ]]; then
  ask "Destructive git operation (reset --hard / clean -f). Confirm nothing valuable is uncommitted."
fi

if [[ "$c" == *"--no-verify"* ]] || [[ "$c" == *"--no-gpg-sign"* ]] || [[ "$c" == *"commit.gpgsign=false"* ]]; then
  ask "Command skips git hooks or commit signing. Confirm the user asked for this."
fi

if [[ "$c" == *"migrate:fresh"* ]] || [[ "$c" == *"migrate:reset"* ]] || [[ "$c" == *"db:wipe"* ]]; then
  ask "This artisan command drops database data. Confirm local/dev target, not production."
fi

if [[ "$c" == *"mysql"* ]] && [[ "$c" =~ (DROP[[:space:]]+(DATABASE|TABLE)|TRUNCATE) ]]; then
  ask "Raw destructive SQL (DROP/TRUNCATE). Confirm target database."
fi

if [[ "$c" =~ chmod[[:space:]]+(-R[[:space:]]+)?777 ]]; then
  ask "chmod 777 detected. Prefer fixing ownership instead of world-writable perms."
fi

if [[ "$c" =~ ^sudo([[:space:]]|$) ]] || [[ "$c" =~ ^su([[:space:]]|$) ]]; then
  ask "Elevated privileges requested. Confirm before running as root."
fi

if [[ "$c" =~ rm[[:space:]]+(-[a-zA-Z]*[rR][a-zA-Z]*[fF][a-zA-Z]*|-[a-zA-Z]*[fF][a-zA-Z]*[rR][a-zA-Z]*)[[:space:]] ]]; then
  ask "Recursive forced delete (rm -rf). Confirm the target path."
fi

exit 0
