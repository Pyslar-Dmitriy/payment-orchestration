---
name: fix-github-pipelines
description: Locate and fix a failing GitHub Actions pipeline using the gh CLI. Use when the user says a pipeline is failing or asks to fix CI.
---

# Fix GitHub Pipelines

Locate the failing pipeline run, diagnose the root cause, fix it, and verify the fix.

## Step 1 — Find the failing run

Identify what is failing. Start from the current branch unless the user specifies otherwise:

```bash
# List recent runs on the current branch
gh run list --branch $(git branch --show-current) --limit 10

# If a specific PR is mentioned
gh pr checks <PR-number>
```

Note the run ID of the failing run.

## Step 2 — Read the failure

Fetch the full logs for the failing job(s):

```bash
# View run summary
gh run view <run-id>

# Stream logs for the failing job
gh run view <run-id> --log-failed
```

Read the complete error output. Do not guess the cause — read the actual failure message.

## Step 3 — Locate the workflow definition

Find the workflow file that owns this run:

```bash
ls .github/workflows/
```

Read the relevant workflow file in full. Understand the job structure, steps, conditions, and any matrix configuration before proposing a fix.

## Step 4 — Diagnose the root cause

Classify the failure:

- **Application code** — a test is failing, static analysis reports an error, or the build breaks.
- **Workflow configuration** — wrong runner, missing secret/env var, incorrect path filter, broken step command.
- **Infrastructure / flakiness** — transient network issue, external service unavailable, timing-dependent test.
- **Dependency** — a package version changed, a lockfile is out of sync, a Docker image tag moved.

Do not patch symptoms. Find the actual root cause.

## Step 5 — Fix the problem

Apply the minimal fix:

- For **application code failures**: read the relevant source files, understand the breakage, fix the code or test. Do not suppress the failure with `continue-on-error` or skip flags.
- For **workflow configuration issues**: edit the workflow YAML — correct the command, path, condition, or environment variable.
- For **dependency issues**: update the lockfile or pin the dependency to a known-good version; document why.
- Never use `--no-verify`, `continue-on-error: true`, or `if: always()` to hide a real failure.

If the fix touches application code, run the affected tests locally first:

```bash
cd apps/<service-name> && php artisan test
```

## Step 6 — Verify the fix locally where possible

For workflow issues, check the YAML is valid and the logic is correct by re-reading the edited file.
For code issues, confirm tests pass before pushing.

## Step 7 — Report

Summarise:
- Which run/job failed and what the error was.
- Root cause (one sentence).
- What was changed and why.
- What to watch for in the next run (e.g. if a secret needs to be added in GitHub repository settings — something that cannot be fixed in code).