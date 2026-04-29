---
name: pre-push-self-review
description: BEFORE every `git push`, run a systematic self-review on the diff against a checklist of the recurring footguns Copilot has flagged in this codebase. The goal is to catch issues that would otherwise consume Copilot review cycles. Trigger BEFORE typing `git push`, after `git commit`, when about to open a PR with `gh pr create`, when running the local test gate, or when the user asks to "review my changes" / "lint diff" / "check diff".
---

# Pre-push self-review checklist — MANDATORY

## Why this skill exists

Copilot (the GitHub PR reviewer) is excellent at catching footguns the
agent missed. But every Copilot review cycle costs minutes of waiting
and a CI run, and signals to the reviewer that the agent's first pass
was sloppy. The agent must catch these issues itself before pushing.

This skill codifies the recurring failure patterns Copilot has flagged
on Padosoft / lopadova repos. Running through the checklist before
every push has two effects:

1. The push goes in clean (Copilot says "0 comments generated") on the
   first or second round, not the fifth.
2. The agent gets faster over time because the checklist becomes
   internalised.

## When to invoke

**Before** `git push` to a PR branch. The flow:

```
git add → git commit → [INVOKE THIS SKILL → check the diff against the checklist] → git push
```

If any item fails, fix it, amend or add a commit, and re-run the
checklist before pushing.

## The checklist

Read the diff with `git diff HEAD~1..HEAD` (or `git diff --staged` if
not yet committed) and walk through every item below.

### A — Type safety on user input

- [ ] **No bare `(int)` cast on env-var / config / request input.** Use
      `is_numeric()` + range check + safe fallback. Example:
      ```php
      // ❌ Wrong — REGOLO_TIMEOUT="abc" silently becomes 0 (= no timeout)
      $timeout = (int) ($_ENV['REGOLO_TIMEOUT'] ?? 60);

      // ✅ Right — validation gate + safe fallback
      $configured = $_ENV['REGOLO_TIMEOUT'] ?? null;
      if ($configured === null || ! is_numeric($configured)) {
          return 60;
      }
      $timeout = (int) $configured;
      return $timeout >= 1 ? $timeout : 60;
      ```
- [ ] **No bare `(float)`, `(bool)`, `(array)` cast on user input.**
      Same rule.
- [ ] **`Http::timeout(0)` = no timeout at all** on the underlying
      Guzzle client. Validate `>= 1` before passing.

### B — Env-var lookup

- [ ] **Read from all three superglobals** when looking up env vars:
      `getenv()`, `$_ENV[]`, `$_SERVER[]`. PHPUnit `<server>` blocks
      and CI runners populate different ones. Treat empty string as
      unset.
      ```php
      foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
          if ($candidate === false || $candidate === null || $candidate === '') {
              continue;
          }
          return (string) $candidate;
      }
      return null;
      ```

### C — Defaults and precedence

- [ ] **No hardcoded default that bypasses config.** Defaults must
      flow through a precedence chain that consumers can override:
      `per-call argument → config entry → safe hard default`. Document
      the chain in the function docblock.
- [ ] **A docblock claim must match the implementation.** If the
      docblock says "X uses Y" but X actually uses Z, the docblock is
      a bug. Diff the description against the code.

### D — Markdown / GitHub rendering

- [ ] **Anchor links to emoji-prefixed headings**: GitHub's heading
      slugger strips the emoji **and** the leading whitespace, so the
      slug is `ai-vibe-coding-pack-included`, NOT
      `-ai-vibe-coding-pack-included`. Test the link before pushing
      with `grep -n '#-' README.md` — any leading-dash anchor on an
      emoji heading is broken.
- [ ] **Cross-reference links** target the section that actually
      contains the explanation, not an unrelated section.

### E — Test assertions

- [ ] **Argument order on PHPUnit ordering assertions is dangerous.**
      `assertGreaterThanOrEqual($expected, $actual)` asserts
      `$actual >= $expected`. For ordering invariants, prefer
      `assertTrue($a >= $b, "diagnostic message with both values")`
      with a printf-formatted failure message — readers do not have
      to remember the argument convention.
- [ ] **Test name = test body.** R16 — failure-path tests must
      actually fire the failure; ordering tests must use
      strictly-monotonic fixtures with strict comparisons.
- [ ] **Public API change ships with a unit test that pins the new
      behaviour.** Without the pin, a regression in the next PR can
      silently undo the fix.

### F — Reflection on `final` classes

- [ ] **`ReflectionMethod::invoke()` on a protected method requires
      `setAccessible(true)`** before invocation. Skipping it raises
      `ReflectionException` at runtime.

### G — Quality gates

Before pushing run the full local quality gate the repo ships:

- [ ] `vendor/bin/phpunit` or equivalent — green
- [ ] `vendor/bin/phpstan analyse` — `[OK] No errors`
- [ ] `vendor/bin/pint --test` — passed (run `vendor/bin/pint` to
      autofix if it fails)
- [ ] (if applicable) frontend / E2E gates the repo has — green

If any of these is red, do NOT push. Fix locally first.

### H — Diff hygiene

- [ ] **No accidental `dd()` / `dump()` / `console.log` / breakpoint
      / `TODO` from debugging in the diff.** R-rule: no debug
      statements in commit.
- [ ] **No commented-out code** unless followed by a clear
      `Why:` line explaining the deliberate choice.
- [ ] **No verbatim quotes from private conversations / named
      individuals** in repo-visible content (commits, PRs, READMEs,
      docs). Use neutral prose. Verbatim quotes only in private
      memory / private workspace.

### I — Recurring footguns specific to this codebase

- [ ] **`Storage::put / delete / copy` return value checked.** R4 —
      no silent failures.
- [ ] **`fnmatch()` on path-like inputs uses `FNM_PATHNAME`.** R19.
- [ ] **LIKE escaping is complete** — `%`, `_`, `\\`. R19.
- [ ] **CSV env-var split** uses `array_filter(array_map('trim',
      explode(',', $raw)))`. R19.
- [ ] **Tenant-aware Eloquent query** scoped via `forTenant($id)` or
      explicit `where('tenant_id', ...)`. R30.
- [ ] **Soft-delete-aware** — write paths on already-deleted rows
      use `withTrashed()` / `onlyTrashed()`. R2.

## Anti-patterns

- ❌ Pushing first, fixing what Copilot finds. The agent must catch
  these issues in the first round.
- ❌ Treating "PHPUnit green" as sufficient. PHPUnit will not catch a
  bare `(int)` cast on a non-numeric env var until the env var is
  actually misconfigured in production.
- ❌ "Copilot will tell me if there's a problem." Copilot is the
  safety net, not the first line of defence. Each Copilot iteration
  costs CI time, reviewer attention, and confidence.

## Application example

The reference moment for this rule is `padosoft/laravel-ai-regolo`
PR #7 (Apr 2026). Five Copilot review rounds were spent on issues
that this checklist would have caught at round one:

| Round | Issue                                       | Checklist item that catches it |
|-------|---------------------------------------------|--------------------------------|
| 1     | `47 / 100 assertions` outdated count        | C — docblock matches code      |
| 2     | `gh pr push` not a real CLI                 | C — docblock matches code      |
| 3     | Anchor `#-ai-vibe-coding-pack-included`     | D — emoji heading slug         |
| 4     | `Http::timeout(0)` from `(int)` cast        | A — type safety on user input  |
| 5     | `embeddingsDimensions()` same `(int)` cast  | A — type safety on user input  |

After this skill ships, the equivalent Round 1 should generate 0–1
Copilot must-fix comments instead of 5–6.

## Maintenance

When Copilot flags a new recurring footgun on any Padosoft / lopadova
repo, append it to the relevant section of this checklist in a follow-
up PR. The checklist grows by experience.
