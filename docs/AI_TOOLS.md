# Herramientas de IA (Cursor + Claude Code)

Este repo está preparado para **Cursor** y **Claude Code** a la vez, sin
divergir el producto.

## Fuente de verdad

| Artefacto | Rol |
|---|---|
| [`CLAUDE.md`](../CLAUDE.md) | Biblia del producto (sprints, roles, seguridad, decisiones). La leen **ambos**. En Cursor se aplica siempre. |
| [`docs/ai/rules/*.md`](ai/rules/) | Reglas detalladas **editables** (estilo, tests, API, servidor). |
| `.cursor/rules/*.mdc` | Reglas Cursor (frontmatter `globs` / `alwaysApply`). **Generadas**. |
| `.claude/rules/*.md` | Reglas Claude Code (`paths`). **Generadas**. |
| `.cursor/agents` + `.claude/agents` | Subagentes (mismo contenido en ambos). |
| `.cursor/commands` + `.claude/commands` | Slash commands (`review`, `fix-issue`). |
| [`scripts/ai/validate-bash.sh`](../scripts/ai/validate-bash.sh) | Hook de shell compartido (deny/ask). |
| `.cursor/hooks.json` | Cableado Cursor → `beforeShellExecution`. |
| `.claude/settings.json` | Cableado Claude → `PreToolUse` Bash. |

No uses un `AGENTS.md` que copie `CLAUDE.md`: Cursor cargaría el mismo texto
dos veces.

## Editar reglas

1. Cambia solo archivos en `docs/ai/rules/`.
2. Regenera ambos árboles:

```bash
bash scripts/ai/sync-rules.sh
```

3. Commit de fuente + generados (`.cursor/rules`, `.claude/rules`).

La regla `project-precedence` deja claro que este proyecto **no** es el
dashboard VPN-only; manda `CLAUDE.md`.

## Precedencia

1. `CLAUDE.md`
2. Reglas generadas (`.cursor/rules` / `.claude/rules`)
3. Instrucciones del agente/comando invocado

Si algo en `.claude/rules` antiguas del dashboard contradice `CLAUDE.md`,
gana `CLAUDE.md` (y tras esta migración esas reglas ya están alineadas al
sistema de tickets).

## Hooks

El validador entiende payload Cursor (`{command}`) y Claude
(`{tool_input.command}`) y responde en el formato de cada uno. Prueba:

```bash
bash scripts/ai/smoke-test-hook.sh
```

## Qué no vive en git

`.gitignore` ignora basura local de Cursor, pero **versiona** rules, agents,
commands y hooks del proyecto (ver excepciones `!.cursor/...`).
