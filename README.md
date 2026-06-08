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
