# TKIF -> N8N Outbound Push Workflow

This folder contains an importable N8N workflow for receiving outbound push events from TKIF.

## Files

- `tkif_outbound_push_workflow.json`: Import into N8N as a workflow.
- `tkif_outbound_actions_workflow.json`: Optional production actions pipeline (Slack-ready stubs).

## N8N Environment Variables

Set these in your N8N runtime:

- `TKIF_OUTBOUND_SECRET`: Must exactly match `OUTBOUND_PUSH_SECRET` in TKIF.
- `TKIF_HMAC_TOLERANCE_SECONDS`: Optional. Default `300`.

## Import Steps

1. In N8N, go to Workflows.
2. Click Import from file.
3. Select `n8n/tkif_outbound_push_workflow.json`.
4. Save and activate.
5. Copy the production webhook URL of node `TKIF Webhook`.

## Optional Second Workflow (Actions Pipeline)

1. Import `n8n/tkif_outbound_actions_workflow.json`.
2. Activate it and copy webhook URL from node `Actions Webhook`.
3. In receiver workflow, replace handler nodes with an HTTP Request node that posts to this URL.
4. Set optional env var `TKIF_SLACK_WEBHOOK_URL` to enable Slack posting.

## TKIF Configuration

Configure TKIF `.env`:

- `OUTBOUND_PUSH_ENABLED=true`
- `OUTBOUND_PUSH_ENDPOINT=<your n8n webhook URL>`
- `OUTBOUND_PUSH_SECRET=<shared secret>`
- `OUTBOUND_PUSH_TIMEOUT_SECONDS=15`
- `OUTBOUND_PUSH_ROUTE=n8n_global`

## Signature Verification

Workflow verifies:

1. `X-TKIF-Timestamp` exists and is within tolerance.
2. `X-TKIF-Signature` matches `sha256=HMAC_SHA256(timestamp + "." + raw_body, secret)`.

If verification fails, workflow returns HTTP 401.

## Routed Events

- `application_submitted`
- `application_approved`
- `application_rejected`
- `application_rejected_blacklist`
- fallback branch for unknown events

You can extend each handler node to integrate with Slack, email, DB, or other systems.

## Suggested Wiring

For cleaner architecture:

1. Keep `tkif_outbound_push_workflow.json` as verify + route + acknowledge.
2. Forward normalized payload from receiver to `tkif_outbound_actions_workflow.json`.
3. Put all business actions (Slack/email/DB) in actions workflow only.
