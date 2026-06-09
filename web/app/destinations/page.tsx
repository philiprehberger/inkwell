const destinations = [
  {
    name: 'Email',
    config: 'to (array of addresses), subject_template, body_template (optional)',
    notes:
      'Always sends from noreply@inkwell.philiprehberger.com. Submitter email goes in body + Reply-To only — never as From. All header values pass through CRLF strip.',
  },
  {
    name: 'Webhook',
    config: 'url (https), secret (≥ 16 chars)',
    notes:
      'HMAC-SHA256 in Stripe-style X-Inkwell-Signature: t=…,v1=… header. 48-hour rotation grace — old + new signatures both sent. SSRF guard refuses private / loopback / link-local IPs.',
  },
  {
    name: 'Slack',
    config: 'webhook_url (hooks.slack.com)',
    notes: 'Block Kit message. Length-limit-aware truncation.',
  },
  {
    name: 'Discord',
    config: 'webhook_url (discord.com/api/webhooks)',
    notes: 'Embed format with field mapping. Length-limit-aware truncation.',
  },
  {
    name: 'Google Sheets',
    config: 'spreadsheet_id, sheet_name, access_token, field_mapping (optional)',
    notes: 'Append-row per submission. OAuth access token managed via your Google Cloud console.',
  },
  {
    name: 'HubSpot',
    config: 'access_token, property_mapping (optional)',
    notes: 'Upsert contact by email; default mapping handles name → firstname/lastname split, phone, notes.',
  },
  {
    name: 'Mailchimp',
    config: 'api_key (with -dcN suffix), audience_id, tags, double_opt_in',
    notes: 'Datacenter parsed from api_key suffix. Single or double opt-in; tags + merge fields supported.',
  },
];

export default function DestinationsPage() {
  return (
    <div className="pb-20">
      <h1 className="text-3xl font-bold tracking-tight">Destinations</h1>
      <p className="mt-3 max-w-3xl text-(--color-ink-dim)">
        Each destination implements the same <code>Destination</code> interface — config validation
        at save, idempotent delivery, periodic health probe. Adding the 8th destination is one class
        + one config-array entry.
      </p>

      <ul className="mt-10 divide-y divide-(--color-paper-dim) overflow-hidden rounded-lg border border-(--color-paper-dim) bg-white">
        {destinations.map((d) => (
          <li key={d.name} className="p-5">
            <div className="flex items-baseline justify-between gap-4">
              <h3 className="text-lg font-semibold">{d.name}</h3>
              <code className="text-xs text-(--color-ink-dim)">{d.config}</code>
            </div>
            <p className="mt-2 text-sm text-(--color-ink-dim)">{d.notes}</p>
          </li>
        ))}
      </ul>

      <section className="mt-12">
        <h2 className="text-xl font-semibold">v2 destinations (planned)</h2>
        <p className="mt-2 text-sm text-(--color-ink-dim)">
          Each follows the same shape — implement the interface, register, write a smoke test.
          Gated on buyer signal.
        </p>
        <ul className="mt-4 flex flex-wrap gap-2">
          {['Notion', 'Airtable', 'Microsoft Teams', 'Pipedrive', 'Zoho CRM'].map((name) => (
            <li
              key={name}
              className="rounded-full border border-(--color-paper-dim) px-3 py-1 text-sm text-(--color-ink-dim)"
            >
              {name}
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
