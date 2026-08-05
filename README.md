# Inkwell

> Form submission / inbound API — accept POSTs from any HTML form, filter spam transparently, forward to email + webhook + Slack + Discord + Google Sheets + HubSpot + Mailchimp.

- **Docs / live demo:** [inkwell.philiprehberger.com](https://inkwell.philiprehberger.com) *(coming soon)*
- **API host:** [api.inkwell.philiprehberger.com](https://api.inkwell.philiprehberger.com) *(coming soon)*
- **Stack:** Laravel 13 + Filament v5 + MySQL 8 + Redis + Horizon + Next.js 16 + Scalar
- **Plan:** see `~/projects/income-ops/.scratch/plans/form_submission_api_portfolio.md`

This is not a production service. It's a portfolio demonstration that the same architect can ship a form-submission product end-to-end — REST API + targeting rule engine for spam scoring + multi-destination fan-out + a Filament admin where the buyer actually wants to live + atomic-release deploy — not just a curl example behind a README.

## Quickstart (visitor side)

```html
<form action="https://api.inkwell.philiprehberger.com/v1/forms/<form-id>/submit" method="post">
  <input name="name" required>
  <input name="email" type="email" required>
  <textarea name="message" required></textarea>
  <input type="hidden" name="_redirect" value="https://example.com/thanks">
  <input type="text" name="_subject_honeypot" style="display:none">
  <button type="submit">Send</button>
</form>
```

No JavaScript required. Works with JS off, works in HTML emails, works for screen readers.

## Repo layout

```
inkwell/
├── app/             Laravel 13 application (API + Filament admin)
├── openapi/
│   └── spec.yaml    OpenAPI 3.1 — source of truth
├── web/             Next.js 16 docs + marketing + live-demo (Phase 7)
├── widget/          3 KB optional JS widget (Phase 7)
├── infra/
│   ├── apache/
│   ├── cron/        PII-purge, StopForumSpam refresh, DestinationHealthJob
│   ├── supervisor/  Horizon fast + slow + ScanUpload
│   └── oauth/       scopes.md + per-connector docs
├── scripts/deploy/  Atomic release-based deploy
├── tests/
│   ├── corpus/      Spam-scoring drift corpus (200+ samples)
│   └── load/        k6 load test profiles
└── docs/runbooks/   Migration rollback + DR restore
```

## Local development

### Prerequisites

- PHP 8.3+, Composer 2.9+, Node 22+/npm 10+, MySQL 8, Redis 7+
- Optional: ClamAV daemon on `127.0.0.1:3310` for file-safety pipeline

### Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # sqlite default; or create MySQL `inkwell` schema
php artisan migrate
php artisan inkwell:seed-admin --email=you@example.com
composer dev
```

Filament admin → http://localhost:8000/admin
API healthz → http://localhost:8000/v1/healthz

## Deployment

Atomic-release deploy to EC2. See `scripts/deploy/deploy.cjs` and `.env.deployment.example`.

```bash
cp .env.deployment.example .env.deployment
npm run deploy
```

## License

MIT

## Authenticated destinations

A webhook destination can carry custom headers, so Inkwell can post into an API that requires a credential rather than only into endpoints that accept anonymous POSTs.

That capability is deliberately constrained, because tenant-controlled headers on a tenant-controlled URL in a multi-tenant service is request forgery with our IP reputation attached:

- Header names are **allowlisted**, not denylisted — a denylist fails open the moment a new dangerous header is standardised.
- `Host`, `Cookie`, `Content-Length` and hop-by-hop headers are rejected regardless of casing or prefix.
- At most 10 headers, values under 2 KB, no CR/LF (header injection).
- Tenant headers can never override a signature or the content type.
- Credential values are **encrypted at rest and never returned after write**. Reads yield a mask; an operator may replace a value, nobody may read one.
- A destination carrying a credential requires its workspace to define an **egress allowlist** first. Uncredentialed destinations are unaffected.

Full threat model: `docs/security/authenticated-destinations.md`.

## Envelope shapes

Three fixed shapes, selected per destination:

| Shape | Body |
|---|---|
| `inkwell-native` *(default)* | The original envelope, unchanged |
| `webhook-relay` | `{"type": …, "payload": {…}}` |
| `flat` | The submission payload alone |

**Templates are deliberately not supported.** A template engine over tenant input is a code-execution surface, and a "restricted expression syntax" is an intention rather than a guarantee. These shapes cover the real requirement with no injection surface.

## Signature schemes

`inkwell-v0` (the original `t=…,v1=…` HMAC) remains the **permanent default**, and its output is byte-identical to before — enforced by a golden regression captured before any of this work began.

`standard-webhooks` is available as an opt-in alternative, implementing the [Standard Webhooks](https://www.standardwebhooks.com/) spec via `philiprehberger/interchange`: `webhook-id` / `webhook-timestamp` / `webhook-signature`, base64 signatures, and rotation as a space-delimited list in one header.

**Switching schemes is not a config flip.** Standard Webhooks secrets are base64 with a `whsec_` prefix and the HMAC key is the decoded bytes, so an existing arbitrary-string secret cannot be reinterpreted. Adopting it on a live destination means issuing a new secret and coordinating with whoever consumes it — which is why it is per-destination and never bulk.

## Tracing

Inkwell accepts, propagates and echoes W3C Trace Context, including **across the queue boundary** — a delivery dispatched from a submission carries the same trace to the receiving service.
