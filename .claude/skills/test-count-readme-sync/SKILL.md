---
name: test-count-readme-sync
description: BEFORE every `git push`, when you have added or removed unit / feature / assertion code in this commit, run the local test suite and reconcile every test-count / assertion-count claim across README.md, the PR description, sample-output blocks, comparison tables, roadmap rows, and inline narrative. Trigger when the commit modifies any `tests/**` file, when you've added or removed an `assert*()` call, when README.md still mentions a previous run's count, or when about to open a PR that touches test files.
---

# Test-count + assertion-count README sync — MANDATORY

## Why this skill exists

Every drift between the actual `vendor/bin/phpunit` output and the
counts documented in `README.md` (or the PR description) costs **one
Copilot review round**. Each round costs minutes of waiting, a CI
job, and reviewer attention — and signals to Lorenzo that the agent
shipped sloppy work.

The root cause is always the same: the agent added or removed tests
in the commit but did not update the count claims sitting in five
different places in the README. Copilot then catches every one of
them and asks for the reconciliation in a follow-up round.

This skill closes the loop at commit-prep time.

## When to invoke

**Before** `git push` on any commit that:

1. Modifies any file under `tests/` (Unit, Feature, Live, Architecture).
2. Adds or removes any `assert*()` / `expect*()` / `assertCount()` /
   `assertSame()` / `assertGreaterThan()` / etc. call.
3. Renames, deletes, or splits a test method.
4. Adds or removes a test class.
5. Touches `phpunit.xml` (testsuite includes / excludes).

When **any** of those is true, the count claims in `README.md` are
suspect and **MUST** be re-derived from the live phpunit output
before the push.

## The check

```
git add → git commit → [INVOKE THIS SKILL] → git push
```

### Step 1 — capture the canonical numbers

Run the suite locally:

```bash
vendor/bin/phpunit
```

(Or the repo-specific equivalent. On packages with `defaultTestSuite="Unit"`
in `phpunit.xml`, this runs only the offline suite.)

Copy the closing line verbatim. It looks like:

```
OK (80 tests, 163 assertions)
```

These two numbers are the **single source of truth** for the entire
PR.

### Step 2 — grep the documentation surface

Search for every existing count claim in the repo:

```bash
grep -nE '[0-9]+ (unit )?tests?( ?(/|,) ?[0-9]+ assertions?)?' README.md
grep -nE 'OK \([0-9]+ tests, [0-9]+ assertions\)' README.md
grep -nE '[0-9]+ (unit )?tests?( ?(/|,) ?[0-9]+ assertions?)?' .github/PULL_REQUEST_TEMPLATE.md docs/ 2>/dev/null
```

Also search the PR description body (`gh pr view <N> --json body --jq .body`).
Common locations:

- `## Features at a glance` bullet
- `## Comparison vs alternatives` table row
- `## Testing` intro paragraph
- Sample `OK (...)` output block under "Testing"
- "Quality gates" section in the PR description
- `## Roadmap` row entries
- Coverage breakdown table

### Step 3 — reconcile every match

For every grep hit, replace the old count with the canonical numbers
captured in Step 1. Use a single `sed` pass for safety:

```bash
sed -i 's/OLD_TESTS unit tests \/ OLD_ASSERTIONS assertions/NEW_TESTS unit tests \/ NEW_ASSERTIONS assertions/g; \
        s/OK (OLD_TESTS tests, OLD_ASSERTIONS assertions)/OK (NEW_TESTS tests, NEW_ASSERTIONS assertions)/g; \
        s/OLD_TESTS tests \/ OLD_ASSERTIONS assertions/NEW_TESTS tests \/ NEW_ASSERTIONS assertions/g' README.md
```

Then re-grep to verify zero hits remain on the old numbers:

```bash
grep -nE 'OLD_TESTS tests|OLD_ASSERTIONS assertions' README.md
```

### Step 4 — patch the PR description

If the PR is already open, update the description body in the same
commit cycle:

```bash
gh pr view <N> --json body --jq .body \
  | sed 's/OLD_TESTS tests, OLD_ASSERTIONS assertions/NEW_TESTS tests, NEW_ASSERTIONS assertions/g' \
  | gh pr edit <N> --body-file -
```

This is **part of the push prep**, not a follow-up.

### Step 5 — final sanity check

Run the suite **one more time** and confirm the closing line still
matches the numbers now in `README.md`:

```bash
vendor/bin/phpunit | tail -3
grep "OK (NEW_TESTS tests, NEW_ASSERTIONS assertions)" README.md
```

If both succeed, push.

## Application example — PR #9 padosoft/laravel-ai-regolo (Apr 2026)

The reference moment for this skill is `padosoft/laravel-ai-regolo`
PR #9 (v0.2 multimodal). Three Copilot review rounds were spent on
test-count drift alone:

| Round | Locations flagged                                    |
|-------|------------------------------------------------------|
| 1     | README intro paragraph: `61 / 123` vs sample `80 / 163` |
| 2     | README + comparison table + roadmap row drift           |
| 3     | PR description `162` vs README `163` (off-by-one)       |

After this skill ships, the equivalent Round 1 should generate **0**
test-count Copilot comments instead of 3+.

## Anti-patterns

- ❌ "I'll update the count later." There is no later — Copilot
  reviews on every push.
- ❌ "Just one place is wrong." Copilot greps the whole README; if
  any count drifts, every drift gets flagged.
- ❌ "PHPUnit says 162, README says 163, off by one is fine." Off
  by one is the **most expensive** drift because it triggers a
  round purely to argue about one assertion.
- ❌ "I'll skip the local run because the test-list-as-text is
  unchanged." The assertion count drifts independently — adding one
  `assertSame()` inside an existing test bumps assertions but not
  tests. Always re-run.

## Maintenance

When a new repo joins the family (padosoft / lopadova), copy this
skill into its `.claude/skills/test-count-readme-sync/` directory.
Aligned with R40 in the canonical CLAUDE.md.
