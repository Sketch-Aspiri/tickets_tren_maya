#!/usr/bin/env bash
# Claude Code PreToolUse wrapper → shared validator (Cursor-compatible too).
exec bash "$(cd "$(dirname "$0")/../.." && pwd)/scripts/ai/validate-bash.sh"
