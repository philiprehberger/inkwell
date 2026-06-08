# OAuth + API-key scopes for Inkwell connectors

Minimum-required scopes per destination. Audit-day liability is real even at portfolio scale — request only what we use.

## Google Sheets

Scope: `https://www.googleapis.com/auth/spreadsheets`
What we do with it: `POST /v4/spreadsheets/<id>/values/<range>:append` to append one row per submission.

What we explicitly DON'T request: `drive.file`, `drive.readonly`. The buyer pastes a spreadsheet ID; we don't enumerate their Drive.

Token refresh: standard OAuth 2.0 refresh-token flow. Refresh-token storage is Laravel `Crypt::encrypt()`-ed in the destination config. Rotation of the app's `APP_KEY` forces reconnect (documented).

## HubSpot

Token type: OAuth access token OR Private App token (buyer's choice).
Scopes:
- `crm.objects.contacts.read` — to upsert by email (lookup-first to detect existing)
- `crm.objects.contacts.write` — to upsert / update

What we explicitly DON'T request: `crm.objects.companies.*`, `crm.objects.deals.*`, `tickets`, `oauth.refresh_token_required`. Inkwell only touches contacts.

## Mailchimp

Auth: API key with datacenter suffix (`key-us21`). OAuth flow is also supported; both end up as the bearer for the audience-members endpoint.
What we do with it: `PUT /3.0/lists/<audience_id>/members/<email_md5>` to upsert.
What we explicitly DON'T touch: campaigns, automations, templates, reports.

## Domain stability commitment

Inkwell's primary domain `inkwell.philiprehberger.com` is a Day-1 commitment to every OAuth app registration. Domain change requires a coordinated 90-day re-registration runbook across all three connectors.

## When a token expires

- Connector returns `AttemptResult::failed(... errorCode: 'oauth_expired' ...)`.
- The `DeliverToDestinationJob` records the attempt and (when error_code matches) dispatches `DestinationDegradedJob`.
- `DestinationDegradedJob` marks the destination `health: degraded`, emails the buyer with a reconnect link, surfaces a red badge in the Filament admin.
- Subsequent submissions skip degraded destinations until the buyer reconnects.

## v2 connectors (planned)

- Notion (database items append, OAuth)
- Airtable (records create, OAuth or API key)
- Microsoft Teams (adaptive card via connector URL — no OAuth)
- Pipedrive (lead create, OAuth)
- Zoho CRM (lead create, OAuth)

Same shape: implement `Destination`, register in `config/inkwell.php`, document scope minimization here.
