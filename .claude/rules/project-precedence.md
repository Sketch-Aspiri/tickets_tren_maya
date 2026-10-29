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
