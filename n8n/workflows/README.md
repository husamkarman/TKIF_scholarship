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
