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

Webhook endpoint expected by PHP app:
- POST `/webhook/scholarship-submit`

## Current Status
- Step 8 is implemented in `scholarship-submit.json`.

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
