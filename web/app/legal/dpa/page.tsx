export default function DPAPage() {
  return (
    <article className="prose pb-20">
      <h1 className="text-3xl font-bold tracking-tight">Data Processing Agreement (template)</h1>
      <p>
        This is the <strong>template DPA</strong> documenting the processor relationship between the
        buyer (data controller) and Inkwell (data processor). For a real engagement we'd
        countersign a copy out of band. The text below is intentionally light — it's a portfolio
        demonstration.
      </p>

      <h2>Data we process</h2>
      <ul>
        <li>Submission payloads — whatever fields the buyer's form schema declares.</li>
        <li>Submitter IP addresses + user-agent strings (metadata column).</li>
        <li>Email addresses (when present in the payload).</li>
        <li>File uploads (when configured per form).</li>
      </ul>

      <h2>Retention</h2>
      <ul>
        <li>Submission payloads: 90 days hot, then PII-purged in place.</li>
        <li>Submission metadata (excluding IP) retained indefinitely for aggregate analytics.</li>
        <li>IP addresses redacted to /24 (IPv4) or /48 (IPv6) at the 90-day mark.</li>
        <li>Delivery attempt response bodies: 30 days, then truncated.</li>
        <li>File uploads: configurable per workspace, default 30 days.</li>
        <li>Audit events: 365 days, then cold-archived.</li>
      </ul>

      <h2>Sub-processors</h2>
      <p>
        See <a href="/legal/sub-processors">/legal/sub-processors</a> for the maintained list. Major
        sub-processors include AWS S3 (us-west-2 by default), MaxMind GeoLite2 (IP geolocation),
        Postmark / Resend (email delivery if configured).
      </p>

      <h2>Subject access + erasure</h2>
      <p>Two endpoints serve subject rights:</p>
      <ul>
        <li>
          <code>POST /v1/data-subjects/lookup</code> — given an email, returns submission IDs +
          form references containing that address (without exposing the payload).
        </li>
        <li>
          <code>DELETE /v1/data-subjects/by-email</code> — queues an asynchronous erasure that
          cascades through submissions, files, delivery attempts, and audit-row redaction. Status
          via the request ID.
        </li>
      </ul>

      <h2>Breach notification</h2>
      <p>
        Inkwell will notify the buyer within 72 hours of becoming aware of a personal data breach
        that affects the buyer's submission data.
      </p>

      <h2>Termination</h2>
      <p>
        On termination of the engagement, Inkwell will return all buyer-controlled data + delete
        any processor-held copies within 30 days.
      </p>

      <p className="text-sm text-(--color-ink-dim)">
        This DPA template is a portfolio artifact, not a legally executed agreement. For production
        use we'd execute a real DPA out of band.
      </p>
    </article>
  );
}
