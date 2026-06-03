# n8n MVP Workflows

Import `scholarship-submit.json` into n8n and activate it.

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
