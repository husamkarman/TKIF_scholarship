# Branch Protection Setup

Use this checklist to require CI before merging to the main branch.

## Goal
Block merges unless the workflow check `Acceptance Smoke` passes.

## GitHub UI Steps
1. Open repository Settings.
2. Go to Branches.
3. Under Branch protection rules, click Add rule.
4. Branch name pattern: `main` (or your default branch).
5. Enable Require a pull request before merging.
6. Enable Require status checks to pass before merging.
7. In status checks, select `Acceptance Smoke / smoke`.
8. Enable Require branches to be up to date before merging.
9. Optional hardening:
- Require approvals.
- Dismiss stale approvals when new commits are pushed.
- Restrict who can push to matching branches.
10. Click Create (or Save changes).

## Verification
1. Open a PR with a small commit.
2. Confirm the required check appears as blocking until green.
3. Confirm merge button stays disabled when check fails.

## Required Check Name
- Workflow: `Acceptance Smoke`
- Job: `smoke`
- Usually shown in GitHub as: `Acceptance Smoke / smoke`
