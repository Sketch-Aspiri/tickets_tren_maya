#!/usr/bin/env bash
# Cursor project hook wrapper → shared validator.
exec bash "$(cd "$(dirname "$0")/../.." && pwd)/scripts/ai/validate-bash.sh"
