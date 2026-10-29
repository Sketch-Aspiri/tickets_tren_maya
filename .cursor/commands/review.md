---
description: Review code just written or modified in the Sistema de Tickets (code-reviewer + security-auditor when sensitive).
argument-hint: [optional: file path or feature name — empty = current uncommitted changes]
---

# Review

Code was just written or modified. Review it before it is considered done.

Target: $ARGUMENTS

Use TodoWrite to track phases.

## Phase 1: Scope

1. If `$ARGUMENTS` is empty, use `git status` / `git diff` (or session files if not a git repo).
2. Otherwise scope to the named path/feature.
3. Read full files in scope — not only hunks.

## Phase 2: Code quality

1. Launch `code-reviewer` with that scope.
2. Apply project checklists from `CLAUDE.md` and `.cursor/rules` / `.claude/rules`.

## Phase 3: Security (conditional)

If the change touches auth, 2FA, roles, input, uploads, email ingestion, exports,
or server/Nginx/MySQL config, also launch `security-auditor`. Pure presentation
with no new input may skip this phase — say so explicitly.

## Phase 4: Consolidate

Merge findings, de-dupe, sort CRITICAL → HIGH → MEDIUM → LOW.
Format: `[SEVERITY] file:line — issue — fix`.

## Phase 5: Verdict

- **Approve** — no CRITICAL/HIGH
- **Warning** — HIGH only
- **Block** — any CRITICAL

Ask before proceeding past CRITICAL.

## Phase 6: Fix (if requested)

Delegate non-trivial fixes to `code-writter`, re-test, re-review.

## Phase 7: Summary

Scope, verdict, open vs resolved findings. Do not commit unless the user asks.
