# n8n MVP Workflows

Import `scholarship-submit.json` into n8n and activate it.

## Step 2: Repo-Connected Agent Pipeline

Import `dashboard-automation-step2.json` for the second-stage automation path.

What this workflow does:
- Loads repository context from your workspace path.
- Builds agent prompts for Code Creation, Validation, Security, and UI agents.
- Executes Code Creation Agent and Validation Agent through your agent orchestrator endpoint.
- Enforces a human approval gate before progressing to the next step.
- Stops safely when contract validation fails, validation fails, or approval is not granted.

Required n8n environment variables:
- `AGENT_ORCHESTRATOR_URL` (for example `http://agent-gateway:9000`)

Optional workflow input payload fields:
- `task_id`
- `objective`
- `workspace_path`
- `scope_files`
- `constraints`
- `acceptance_criteria`

Approval gate behavior:
- Wait node webhook suffix: `dashboard-step2-approval`
- Resume payload must include `approved=true` to pass.
- Any other value keeps the run in `Stop - Needs Changes`.

## Step 3: Security Agent Integrated Pipeline

Import `dashboard-automation-step3.json` for the third-stage automation path.

What Step 3 adds:
- Runs Security Review Agent after Validation Agent passes.
- Blocks progression when security review fails (`pass=false`) or returns `blocked=true`.
- Keeps human approval required before completing the step.

Execution path in Step 3:
- Code Creation Agent
- Validation Agent
- Security Review Agent
- Human Approval Gate

Step 3 approval gate:
- Wait node webhook suffix: `dashboard-step3-approval`
- Resume payload must include `approved=true`.

Required environment variables:
- `AGENT_ORCHESTRATOR_URL`

Expected orchestrator endpoints used in Step 3:
- `POST {AGENT_ORCHESTRATOR_URL}/code-creation`
- `POST {AGENT_ORCHESTRATOR_URL}/validation`
- `POST {AGENT_ORCHESTRATOR_URL}/security-review`

## Step 4: UI Agent Integrated Pipeline

Import `dashboard-automation-step4.json` for the fourth-stage automation path.

What Step 4 adds:
- Runs UI Error Fixing Agent after Security Review Agent passes.
- Blocks progression when UI review fails (`pass=false`).
- Keeps human approval required before step completion.

Execution path in Step 4:
- Code Creation Agent
- Validation Agent
- Security Review Agent
- UI Error Fixing Agent
- Human Approval Gate

Step 4 approval gate:
- Wait node webhook suffix: `dashboard-step4-approval`
- Resume payload must include `approved=true`.

Required environment variables:
- `AGENT_ORCHESTRATOR_URL`

Expected orchestrator endpoints used in Step 4:
- `POST {AGENT_ORCHESTRATOR_URL}/code-creation`
- `POST {AGENT_ORCHESTRATOR_URL}/validation`
- `POST {AGENT_ORCHESTRATOR_URL}/security-review`
- `POST {AGENT_ORCHESTRATOR_URL}/ui-fixing`

## Step 5: Controlled Patch Apply + Rollback + Audit Trail

Import `dashboard-automation-step5.json` for the fifth-stage automation path.

What Step 5 adds:
- Executes controlled patch apply only after all agents pass and human approval is granted.
- Performs `git apply --check` preflight before real patch apply.
- Creates rollback artifact before apply.
- Writes apply audit records to a local log file.

Execution path in Step 5:
- Code Creation Agent
- Validation Agent
- Security Review Agent
- UI Error Fixing Agent
- Human Approval Gate
- Controlled Patch Apply

Step 5 approval gate:
- Wait node webhook suffix: `dashboard-step5-approval`
- Resume payload must include `approved=true`.

Rollback and audit artifacts:
- Rollback file path pattern:
	- `n8n/workflows/audit/rollback_<task_id>_<timestamp>.diff`
- Applied patch file path pattern:
	- `n8n/workflows/audit/patch_<task_id>_<timestamp>.diff`
- Audit log file:
	- `n8n/workflows/audit/apply_audit.log`

Safety notes:
- Patch apply is blocked when `git apply --check` fails.
- Workflow returns to `Stop - Needs Changes` if apply fails.
- Keep running in a non-production branch/workspace unless explicitly approved.

## Step 6: Post-Apply Verification + Automatic Rollback

Import `dashboard-automation-step6.json` for the sixth-stage automation path.

What Step 6 adds:
- Runs post-apply verification after patch apply succeeds.
- Verifies changed PHP files with `php -l`.
- Optionally runs `scripts/acceptance_smoke.sh` when present and executable.
- Automatically executes rollback (`git apply -R`) when verification fails.

Execution path in Step 6:
- Code Creation Agent
- Validation Agent
- Security Review Agent
- UI Error Fixing Agent
- Human Approval Gate
- Controlled Patch Apply
- Post-Apply Verification
- Auto Rollback on verification failure

Step 6 approval gate:
- Wait node webhook suffix: `dashboard-step6-approval`
- Resume payload must include `approved=true`.

Verification and rollback behavior:
- Verification success returns `approved_step6` status.
- Verification failure triggers automatic rollback and returns `rolled_back_after_verify_failure`.
- Rollback appends an entry to the apply audit log when audit path is available.

Operational notes:
- Set `SKIP_SMOKE=1` in execution environment to bypass smoke script execution.
- Keep this workflow in controlled branches/workspaces before production rollout.

## Step 7: Deployment Gating + Release Checklist

Import `dashboard-automation-step7.json` for the seventh-stage automation path.

What Step 7 adds:
- Generates a release checklist from verification output and changed files.
- Flags critical file changes (for example route/controller and SQL changes) for stricter release checks.
- Adds a final deployment approval gate after verification has passed.

Execution path in Step 7:
- Code Creation Agent
- Validation Agent
- Security Review Agent
- UI Error Fixing Agent
- Human Approval Gate (pre-apply)
- Controlled Patch Apply
- Post-Apply Verification
- Release Checklist Builder
- Deployment Approval Gate

Step 7 approval gates:
- Pre-apply gate suffix: `dashboard-step7-approval`
- Deployment gate suffix: `dashboard-step7-deploy-approval`
- Both gates require `approved=true` in resume payload.

Checklist behavior:
- Produces `release_checklist` items in workflow output.
- Marks additional required checks when critical files were changed.
- Continues to auto-rollback path if verification fails before deployment gate.

Step 7 status behavior:
- Success returns `approved_step7`.
- Any rejection/failure path returns `needs_changes`.

## Step 8: App Deploy Hook + Monitoring Registration

Import `dashboard-automation-step8.json` for the eighth-stage automation path.

What Step 8 adds:
- Calls the PHP app deploy hook endpoint after deployment approval.
- Registers deployment events directly in app inbox/audit so they appear in the Dashboard for Admin/IT.
- Treats hook failure as a hard stop (`needs_changes`).

Execution path in Step 8:
- All Step 7 checks and gates
- App Deploy Hook call (`/?page=dashboard_automation_apply`)
- Deploy Hook success gate

Step 8 required environment variables:
- `TKIF_APP_BASE_URL` (example: `http://localhost/TKIF_scholarship`)
- `DASHBOARD_AUTOMATION_TOKEN` (should match app `notifications.worker_token`)

Step 8 status behavior:
- Success returns `approved_step8`.
- Hook failure/rejection returns `needs_changes`.

App endpoint details:
- Endpoint: `POST /?page=dashboard_automation_apply`
- Auth: header `X-Worker-Token`
- Supported modes: `register_deploy`, `check`, `apply`, `rollback`
- Writes file audit entries to `n8n/workflows/audit/apply_audit.log`
- Stores structured events in `notification_inbox` as `dashboard_automation_*`

## Step 9: Scheduled Rollout Finalization + Post-Release Monitoring

Import `dashboard-automation-step9.json` for the ninth-stage automation path.

What Step 9 adds:
- Adds a post-release approval checkpoint after deploy hook success.
- Registers a dedicated post-release monitoring event in the PHP app.
- Finalizes rollout only if monitoring registration succeeds.

Execution path in Step 9:
- All Step 8 checks and gates
- Monitoring registration payload builder
- Post-release approval gate
- Post-release monitoring registration hook call
- Monitoring registration success gate

Step 9 approval gates:
- Pre-apply gate suffix: `dashboard-step9-approval`
- Post-release gate suffix: `dashboard-step9-monitor-approval`
- Both gates require `approved=true` in resume payload.

Step 9 status behavior:
- Success returns `approved_step9`.
- Any hook failure or approval rejection returns `needs_changes`.

Operational result:
- Creates additional `dashboard_automation_register_deploy` event entries for monitoring handoff.
- Keeps final release state visible in Admin/IT dashboard deploy events.

## Step 10: Auto Commit/Push Before Every Approval

Import `dashboard-automation-step10.json` for the tenth-stage automation path.

What Step 10 adds:
- Enforces git snapshot checkpoints before each approval gate.
- Performs `git add -A`, commit, and push in the workflow execution environment.
- Blocks approval progression when commit/push fails.

Execution path in Step 10:
- All Step 9 checks and gates
- Pre-apply snapshot commit/push gate
- Pre-deploy snapshot commit/push gate
- Pre-monitoring snapshot commit/push gate

Step 10 approval gates:
- Pre-apply gate suffix: `dashboard-step10-approval`
- Deployment gate suffix: `dashboard-step10-deploy-approval`
- Post-release gate suffix: `dashboard-step10-monitor-approval`

Step 10 git environment variables:
- `DASHBOARD_AUTOMATION_BRANCH` (optional; if set, workflow checks out branch before snapshot)
- `DASHBOARD_AUTOMATION_REMOTE` (optional; default `origin`)

Step 10 status behavior:
- Success returns `approved_step10`.
- Commit/push failure or approval rejection returns `needs_changes`.

Safety notes for Step 10:
- Configure git credentials on n8n worker host in advance.
- Use protected branches with required reviews where applicable.
- Snapshot node performs an allow-empty checkpoint commit to enforce a real push before approval.

## Step 11: Branch Guardrails + Rollback Drill

Import `dashboard-automation-step11.json` for the eleventh-stage automation path.

What Step 11 adds:
- Enforces branch guardrails (blocks direct automation runs on `main`/`master`).
- Performs rollback drill checks against generated patch artifacts.
- Adds a final hardening approval gate after rollback drill and snapshot checkpoint.

Execution path in Step 11:
- All Step 10 checks and gates
- Branch guardrail check
- Rollback drill check (`git apply -R --check` + forward check)
- Final pre-hardening snapshot commit/push checkpoint
- Final hardening approval gate

Step 11 approval gates:
- Pre-apply gate suffix: `dashboard-step11-approval`
- Deployment gate suffix: `dashboard-step11-deploy-approval`
- Post-release gate suffix: `dashboard-step11-monitor-approval`
- Final hardening gate suffix: `dashboard-step11-hardening-approval`

Step 11 status behavior:
- Success returns `approved_step11`.
- Any guardrail/drill/snapshot/approval failure returns `needs_changes`.

Operational notes:
- Set `DASHBOARD_AUTOMATION_BRANCH` to enforce a specific release branch name.
- Keep `DASHBOARD_AUTOMATION_REMOTE` aligned with your CI release remote.
- Rollback drill validates reversibility without mutating working tree content.

## Step 12: Production Schedule Activation + Runbook Handoff

Import `dashboard-automation-step12.json` for the twelfth-stage automation path.

What Step 12 adds:
- Activates production rollout schedule metadata via app deploy hook.
- Creates a final snapshot commit/push checkpoint before operational handoff.
- Adds runbook handoff approval gate for steady-state operations transition.

Execution path in Step 12:
- All Step 11 checks and gates
- Production schedule payload builder
- Production schedule activation hook call
- Pre-handoff snapshot commit/push checkpoint
- Runbook handoff approval gate

Step 12 approval gates:
- Pre-apply gate suffix: `dashboard-step12-approval`
- Deployment gate suffix: `dashboard-step12-deploy-approval`
- Post-release gate suffix: `dashboard-step12-monitor-approval`
- Final hardening gate suffix: `dashboard-step12-hardening-approval`
- Runbook handoff gate suffix: `dashboard-step12-handoff-approval`

Step 12 environment additions:
- `DEPLOY_RUNBOOK_OWNER` (optional owner label for handoff metadata)

Step 12 status behavior:
- Success returns `approved_step12`.
- Schedule/snapshot/handoff failure returns `needs_changes`.

Webhook endpoint expected by PHP app:
- POST `/webhook/scholarship-submit`

## Current Status
- Dashboard automation Step 12 is implemented in `dashboard-automation-step12.json`.
- Submission event workflow remains implemented in `scholarship-submit.json`.

## Implemented Branches
- `application_submitted`
	- Builds submission notification payload.
	- Starts 30-minute wait node for SLA monitoring.
	- Emits escalation payload after wait.
- `application_approved` and `application_rejected`
	- Builds decision notification payload.
- `application_rejected_blacklist`
	- Builds high-priority blacklist alert payload.

## Notes
- Webhook response is immediate (`onReceived`) so PHP is not blocked.
- Current workflow emits structured payloads for downstream channels.
- Workflow is compatible with multiple scholarships and dynamic form criteria.
	- Uses `scholarship_id`, `scholarship_title`, and `answers_json` payload fields.
- Next optional extension: add concrete SMTP/Twilio/WhatsApp nodes per environment credentials.
