---
description: Diagnose and fix a bug in the Sistema de Tickets with TDD (code-writter → code-reviewer → security-auditor when applicable).
argument-hint: [bug description, error message, or reproduction steps]
---

# Fix Issue

Find the root cause and fix it — do not silence the symptom.

Issue report: $ARGUMENTS

Use TodoWrite to track phases.

## Phase 1: Understand

If reproduction steps / screen / error are unclear, ask before coding.
Check `storage/logs/laravel.log` when useful.

## Phase 2: Reproduce

Trace route → middleware → controller → Request/Policy → service → view.
Confirm whether behavior is intentional per `CLAUDE.md` decisions.

## Phase 3: RED test

Write/extend a failing test that reproduces the bug (skip only for pure visuals, with an explicit reason).

## Phase 4: Fix

Minimal fix via `code-writter` (or direct if trivial). Respect Form Request,
Policy, activitylog, no raw concatenated SQL, escaped Blade.

## Phase 5: Verify

GREEN on the new test; run relevant `php artisan test` and Pint.

## Phase 6: Review

`code-reviewer` mandatory; `security-auditor` if sensitive area.

## Phase 7: Summary

Root cause, files touched, tests, review findings. Commit only if the user asks.
