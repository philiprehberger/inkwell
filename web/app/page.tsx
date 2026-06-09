import Link from 'next/link';

const htmlExample = `<form action="https://api.inkwell.philiprehberger.com/v1/forms/<form-id>/submit"
      method="post">
  <input name="name" required>
  <input name="email" type="email" required>
  <textarea name="message" required></textarea>

  <input type="hidden" name="_redirect" value="https://example.com/thanks">
  <input type="text"   name="_subject_honeypot" style="display:none">

  <button type="submit">Send</button>
</form>`;

const curlExample = `curl -X POST https://api.inkwell.philiprehberger.com/v1/forms/<id>/submit \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -d '{"name":"Alice","email":"a@example.com","message":"hi"}'`;

const destinations = [
  ['Email', 'noreply sender + Reply-To; CRLF strip on every header value'],
  ['Webhook', 'HMAC-SHA256 t=…,v1=… + 48-hour secret rotation grace'],
  ['Slack', 'Block Kit, length-limit-aware'],
  ['Discord', 'Embed with field mapping'],
  ['Google Sheets', 'Append-row by OAuth token'],
  ['HubSpot', 'Upsert contact by email'],
  ['Mailchimp', 'Audience members, datacenter-aware'],
];

export default function Home() {
  return (
    <>
      <section className="pt-10 pb-16">
        <p className="text-sm font-semibold uppercase tracking-widest text-(--color-accent)">
          Form submission · explainable spam scoring · multi-destination fan-out
        </p>
        <h1 className="mt-3 text-5xl font-bold tracking-tight">
          Drop one HTML form tag. Inkwell handles the rest — spam, fan-out, replay, retention.
        </h1>
        <p className="mt-6 max-w-3xl text-lg text-(--color-ink-dim)">
          The integration is a plain <code>{'<form action="…">'}</code>. No JavaScript required. The
          backend scores submissions with a transparent signal breakdown, validates against a JSON
          Schema you control, and fans out to email + webhook + Slack + Discord + Google Sheets +
          HubSpot + Mailchimp — configured per form, dispatched in parallel, retried on failure.
        </p>
        <div className="mt-8 flex flex-wrap items-center gap-4">
          <Link
            href="/live-demo"
            className="rounded-md bg-(--color-ink) px-5 py-3 text-sm font-semibold text-white no-underline hover:bg-(--color-accent)"
          >
            Open the live demo
          </Link>
          <Link
            href="/reference"
            className="rounded-md border border-(--color-ink) px-5 py-3 text-sm font-semibold text-(--color-ink) no-underline hover:bg-(--color-paper-dim)"
          >
            API reference
          </Link>
          <a
            href="https://github.com/philiprehberger/inkwell"
            className="rounded-md px-5 py-3 text-sm font-semibold text-(--color-ink-dim) no-underline hover:bg-(--color-paper-dim)"
          >
            Source on GitHub
          </a>
        </div>
      </section>

      <section className="border-y border-(--color-paper-dim) py-12">
        <h2 className="text-2xl font-bold tracking-tight">The canonical integration</h2>
        <p className="mt-2 max-w-3xl text-(--color-ink-dim)">
          Paste this into your HTML. That's the integration. Works on static sites, in HTML emails,
          inside iframes, with JavaScript off, with screen readers. The 3 KB optional widget adds
          inline error rendering and honeypot timing without changing the canonical path.
        </p>
        <div className="mt-6 grid gap-6 lg:grid-cols-2">
          <CodeBlock title="HTML — no JS required" code={htmlExample} />
          <CodeBlock title="curl — for server-side senders" code={curlExample} />
        </div>
      </section>

      <section className="py-16">
        <h2 className="text-2xl font-bold tracking-tight">What's in the box</h2>
        <div className="mt-8 grid gap-8 md:grid-cols-2">
          <Pillar title="Explainable spam scoring">
            <p>
              Every submission shows the per-signal breakdown in the admin — honeypot, IP reputation,
              timing, content density, email validity, captcha. Buyers see <em>why</em> something
              scored what it scored, and adjust thresholds with that signal in view. No black box.
            </p>
          </Pillar>
          <Pillar title="Graduated friction, not binary block">
            <p>
              Submissions in the 30–49 score band don't get rejected outright — they're
              <em> quarantined</em> and a captcha challenge releases them. 50+ becomes spam (no
              destinations dispatched). Honeypot or known-bad IP hard-blocks immediately.
            </p>
          </Pillar>
          <Pillar title="Per-destination fan-out you can trust">
            <p>
              Each destination is its own Horizon job with a (submission, destination, replay_seq)
              idempotency key — worker crashes never double-send. Slow OAuth connectors run on a
              separate queue from webhook/email/Slack so backpressure doesn't compound.
            </p>
          </Pillar>
          <Pillar title="Compliance hooks ready">
            <p>
              <code>POST /v1/data-subjects/lookup</code> finds every submission containing an email.
              <code> DELETE /v1/data-subjects/by-email</code> queues an async erasure that cascades
              through submissions, files, delivery attempts, and audit-row redaction. PII-purge cron
              runs at the 90-day mark. <em>Production-shaped, not production-grade.</em>
            </p>
          </Pillar>
          <Pillar title="Webhook signing, secret rotation with grace">
            <p>
              Webhook destinations sign payloads with HMAC-SHA256 in Stripe's <code>t=…,v1=…</code>
              format. Rotating a destination's secret keeps the old signature valid for 48 hours
              alongside the new one — your receivers update without dropping anything in flight.
            </p>
          </Pillar>
          <Pillar title="File uploads, scanned + EXIF-stripped">
            <p>
              Magic-byte verification against your form's MIME allowlist. Images pass through EXIF
              strip (GPS coordinates removed). ClamAV scan runs out-of-band — submissions
              acknowledge fast, infected files quarantine + flip the submission to{' '}
              <em>quarantined</em>, buyer reviews from the admin.
            </p>
          </Pillar>
        </div>
      </section>

      <section className="border-t border-(--color-paper-dim) py-16">
        <h2 className="text-2xl font-bold tracking-tight">Destinations</h2>
        <p className="mt-3 max-w-3xl text-(--color-ink-dim)">
          Configure any combination per form. Each delivery is independent — a failed Slack
          notification doesn't block the webhook fan-out.
        </p>
        <ul className="mt-8 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
          {destinations.map(([name, blurb]) => (
            <li key={name} className="rounded-lg border border-(--color-paper-dim) bg-white p-4">
              <div className="font-semibold">{name}</div>
              <div className="mt-1 text-sm text-(--color-ink-dim)">{blurb}</div>
            </li>
          ))}
        </ul>
      </section>
    </>
  );
}

function CodeBlock({ title, code }: { title: string; code: string }) {
  return (
    <div className="overflow-hidden rounded-lg border border-(--color-paper-dim)">
      <div className="border-b border-(--color-paper-dim) bg-(--color-paper-dim) px-4 py-2 text-xs font-semibold uppercase tracking-wider text-(--color-ink-dim)">
        {title}
      </div>
      <pre className="m-0 rounded-none bg-(--color-ink) p-5 text-[12.5px] leading-relaxed text-white">
        <code>{code}</code>
      </pre>
    </div>
  );
}

function Pillar({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h3 className="text-lg font-semibold">{title}</h3>
      <div className="mt-2 leading-relaxed text-(--color-ink-dim)">{children}</div>
    </div>
  );
}
