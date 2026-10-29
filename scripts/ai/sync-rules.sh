#!/usr/bin/env bash
# Regenera reglas de Claude Code y Cursor desde docs/ai/rules/*.md (fuente única).
# Uso: bash scripts/ai/sync-rules.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="$ROOT/docs/ai/rules"
CLAUDE_OUT="$ROOT/.claude/rules"
CURSOR_OUT="$ROOT/.cursor/rules"

mkdir -p "$CLAUDE_OUT" "$CURSOR_OUT"

# name|claude_paths|cursor_globs|cursor_always|description
RULES=(
  'code-style|**/*.php,**/*.blade.php,resources/js/**/*.js|**/*.{php,blade.php},resources/js/**/*.js|false|Estilo PHP/Blade/Alpine del sistema de tickets (controladores delgados, Form Requests, Policies, CSP Alpine).'
  'testing|tests/**/*.php,phpunit.xml|tests/**/*.php,phpunit.xml|false|Convenciones de pruebas PHPUnit, IDOR, matriz de roles y checklist por feature.'
  'api-conventions|routes/**/*.php,app/Http/**/*.php,resources/js/**/*.js|routes/**/*.php,app/Http/**/*.php,resources/js/**/*.js|false|Endpoints JSON/HTML parciales internos (sesión+CSRF, Policies, rate limits, adjuntos).'
  'server-conventions|**/.env.example,docs/**/*.md,*.conf|**/.env.example,docs/**/*.{md,MD},**/DEPLOY*.md|false|Infra CloudPanel/MySQL/SSH/deploy de este sitio (público HTTPS; no VPN-only del dashboard).'
)

for entry in "${RULES[@]}"; do
  IFS='|' read -r name claude_paths cursor_globs always description <<<"$entry"
  src_file="$SRC/$name.md"
  if [[ ! -f "$src_file" ]]; then
    echo "Missing source: $src_file" >&2
    exit 1
  fi

  body="$(cat "$src_file")"

  # Claude Code: frontmatter paths (lista YAML)
  {
    echo '---'
    echo 'paths:'
    IFS=',' read -ra paths <<<"$claude_paths"
    for p in "${paths[@]}"; do
      echo "  - \"$p\""
    done
    echo '---'
    echo
    echo "$body"
  } >"$CLAUDE_OUT/$name.md"

  # Cursor: .mdc con globs / alwaysApply
  {
    echo '---'
    echo "description: \"$description\""
    echo "globs: $cursor_globs"
    echo "alwaysApply: $always"
    echo '---'
    echo
    echo "$body"
  } >"$CURSOR_OUT/$name.mdc"
done

# Regla Cursor always-on: precedencia y dualidad (no duplica CLAUDE.md)
cat >"$CURSOR_OUT/project-precedence.mdc" <<'EOF'
---
description: "Precedencia del Sistema de Tickets vs dashboard; dualidad Claude Code + Cursor."
alwaysApply: true
---

# Precedencia y herramientas de IA

Este repo es el **Sistema de Tickets y Control de Actividades (Tren Maya)**,
proyecto independiente del "Dashboard Jefe de Zona" (otra app en la misma VPS).

1. **`CLAUDE.md` (raíz) manda** en producto, sprints, roles, seguridad y
   registro de decisiones. Léelo antes de escribir código.
2. Reglas detalladas por archivo: `.cursor/rules/*.mdc` (Cursor) y
   `.claude/rules/*.md` (Claude Code). Fuente editable única:
   `docs/ai/rules/` — regenerar con `bash scripts/ai/sync-rules.sh`.
3. **No apliques** supuestos del dashboard: VPN-only / un solo usuario /
   “skip auth porque hay un usuario”. Aquí hay registro público con
   aprobación, 100+ usuarios, 3+ roles y 2FA obligatorio en roles altos.
4. Agents/commands: `.cursor/agents` + `.cursor/commands` (Cursor) y
   `.claude/agents` + `.claude/commands` (Claude). Mantén el mismo
   comportamiento en ambos.
5. Hooks de shell: `scripts/ai/validate-bash.sh` (compartido);
   cableado en `.cursor/hooks.json` y `.claude/settings.json`.
6. No crees `AGENTS.md` que copie `CLAUDE.md` (duplicaría contexto en Cursor).
EOF

# Espejo Claude (sin paths = se carga según settings del proyecto / always)
cat >"$CLAUDE_OUT/project-precedence.md" <<'EOF'
---
# Siempre relevante: precedencia del proyecto y dualidad Cursor/Claude.
---

# Precedencia y herramientas de IA

Este repo es el **Sistema de Tickets y Control de Actividades (Tren Maya)**,
proyecto independiente del "Dashboard Jefe de Zona" (otra app en la misma VPS).

1. **`CLAUDE.md` (raíz) manda** en producto, sprints, roles, seguridad y
   registro de decisiones. Léelo antes de escribir código.
2. Reglas detalladas por archivo: `.claude/rules/*.md` (Claude Code) y
   `.cursor/rules/*.mdc` (Cursor). Fuente editable única:
   `docs/ai/rules/` — regenerar con `bash scripts/ai/sync-rules.sh`.
3. **No apliques** supuestos del dashboard: VPN-only / un solo usuario /
   “skip auth porque hay un usuario”. Aquí hay registro público con
   aprobación, 100+ usuarios, 3+ roles y 2FA obligatorio en roles altos.
4. Agents/commands: `.claude/agents` + `.claude/commands` (Claude) y
   `.cursor/agents` + `.cursor/commands` (Cursor). Mantén el mismo
   comportamiento en ambos.
5. Hooks de shell: `scripts/ai/validate-bash.sh` (compartido);
   cableado en `.claude/settings.json` y `.cursor/hooks.json`.
EOF

# Espejo agents/commands: fuente canónica Cursor → Claude (compatibilidad).
if [[ -d "$ROOT/.cursor/agents" ]]; then
  mkdir -p "$ROOT/.claude/agents"
  cp -f "$ROOT/.cursor/agents/"*.md "$ROOT/.claude/agents/" 2>/dev/null || true
fi
if [[ -d "$ROOT/.cursor/commands" ]]; then
  mkdir -p "$ROOT/.claude/commands"
  cp -f "$ROOT/.cursor/commands/"*.md "$ROOT/.claude/commands/" 2>/dev/null || true
fi

echo "Synced rules into .claude/rules and .cursor/rules; mirrored agents/commands to .claude"
