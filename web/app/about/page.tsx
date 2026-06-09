export default function AboutPage() {
  return (
    <article className="prose pb-20">
      <h1 className="text-3xl font-bold tracking-tight">About this demo</h1>

      <p>
        Inkwell is a portfolio demonstration by{' '}
        <a href="https://philiprehberger.com">Philip Rehberger</a>. It is not a production service.
        Don't point real production traffic at it; the live demo's form endpoints are sandboxed and
        the database wipes daily.
      </p>

      <h2>Why this exists</h2>
      <p>
        Most engineering portfolios stop at the README. A buyer evaluating a freelancer for a "buy
        vs build" decision on form-submission infrastructure has to extrapolate from "built a thing
        on GitHub" to "can run a real form backend with spam filtering, multi-destination fan-out,
        and a Filament admin where I'd actually want to live." The extrapolation is expensive.
        Inkwell exists to shorten it.
      </p>
      <p>
        Where my sibling projects sell to API teams (
        <a href="https://github.com/philiprehberger/webhook-relay">webhook-relay</a>) or product
        teams (<a href="https://github.com/philiprehberger/pennant">pennant</a>), Inkwell is shaped
        for small businesses, marketing teams, and indie devs running a Webflow / Eleventy / static
        site who need a backend just for their contact form. The buyer profile is wider, the
        infrastructure flair is lighter, and the admin is a first-class artifact rather than a
        shell.
      </p>

      <h2>What's "production-shaped" mean?</h2>
      <p>
        The architecture is the architecture a real form backend would use: rate-limited public
        endpoint, JSON Schema validation, composable spam-scoring pipeline with explicit decision
        rules, multi-destination fan-out via Horizon, idempotency-keyed delivery jobs, audit log,
        compliance endpoints. But this is one person working on a portfolio — there is no on-call
        rotation, no five-nines SLA, no SOC2 audit. <em>Production-shaped, not production-grade.</em>
      </p>

      <h2>What I'd build for you</h2>
      <p>
        If your team is debating buy vs build on a form backend, or you need a form-submission
        product that integrates with your existing stack (Zapier, Slack, CRM, sheets, …), the
        artifacts on this site are the kind of thing you'd be paying for. Contact me at{' '}
        <a href="https://philiprehberger.com">philiprehberger.com</a> or via{' '}
        <a href="https://www.upwork.com/freelancers/philiprehberger">Upwork</a>.
      </p>

      <h2>What's <strong>not</strong> in v1</h2>
      <p>This is the honest framing — what a real engagement would harden:</p>
      <ul>
        <li>Buyer-facing region residency switching (single S3 region per workspace currently).</li>
        <li>Custom sender domain (per-buyer DKIM record publishing).</li>
        <li>Notion / Airtable / Microsoft Teams / Pipedrive / Zoho CRM destinations.</li>
        <li>Per-workspace spam classifier with feedback loop from Promote / Mark-Spam actions.</li>
        <li>Multi-AZ + ASG disaster-recovery upgrade.</li>
        <li>SOC2 / HIPAA copy.</li>
      </ul>
    </article>
  );
}
