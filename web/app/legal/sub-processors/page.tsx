const subprocessors = [
  ['Amazon Web Services (S3)', 'File upload storage', 'us-west-2 by default'],
  ['MaxMind GeoLite2', 'IP geolocation for country blocklist + abuse analytics', 'Free CC BY-SA 4.0 dataset, 60–90 day update cycle'],
  ['StopForumSpam', 'IP-reputation blocklist for spam scoring signal', 'Free CC BY-SA 4.0 CSV, refreshed nightly'],
  ['Cloudflare Turnstile', 'Optional captcha challenge for quarantined submissions', 'Buyer wires their own site/secret keys'],
  ['Postmark / Resend (optional)', 'Email destination delivery (when buyer configures)', 'Buyer wires their own SMTP / API key per destination'],
  ['Sentry', 'Application error tracking', 'Operator-side; no buyer payload data sent'],
];

export default function SubprocessorsPage() {
  return (
    <article className="pb-20">
      <h1 className="text-3xl font-bold tracking-tight">Sub-processors</h1>
      <p className="mt-3 max-w-3xl text-(--color-ink-dim)">
        Third parties that handle buyer data on Inkwell's behalf. Buyers are notified before any
        change to this list.
      </p>

      <ul className="mt-10 divide-y divide-(--color-paper-dim) overflow-hidden rounded-lg border border-(--color-paper-dim) bg-white">
        {subprocessors.map(([name, purpose, notes]) => (
          <li key={name} className="px-5 py-4">
            <div className="font-semibold">{name}</div>
            <div className="mt-1 text-sm">{purpose}</div>
            <div className="mt-1 text-xs text-(--color-ink-dim)">{notes}</div>
          </li>
        ))}
      </ul>
    </article>
  );
}
