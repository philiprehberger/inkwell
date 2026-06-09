export default function SpamScoringPage() {
  return (
    <article className="prose pb-20">
      <h1 className="text-3xl font-bold tracking-tight">How spam scoring works</h1>
      <p>
        Inkwell scores each submission with a composable pipeline of seven signals. Each signal
        contributes points to a 0–100 total; the form's threshold determines the cutoff between{' '}
        <em>clean</em>, <em>quarantined</em>, and <em>spam</em>. Honeypot and known-bad-IP signals
        hard-block (rejected, no payload stored).
      </p>

      <h2>The seven signals</h2>

      <h3>Honeypot</h3>
      <p>
        A hidden form field that real visitors leave empty. If a bot fills it, the submission is
        rejected — 100 points (hard block). The field name is configurable per form (default{' '}
        <code>_subject_honeypot</code>).
      </p>

      <h3>IP reputation</h3>
      <p>
        StopForumSpam publishes a daily CSV of known-abusive IPs. Inkwell's cron job loads it into a
        Redis SET; per-submission lookup is O(1). A hit hard-blocks. <em>Misses don't add points.</em>
      </p>

      <h3>Timing</h3>
      <p>
        Real visitors take more than two seconds to fill a form. The optional 3 KB widget writes the
        page-render timestamp into a hidden field; submissions under two seconds get +25 points.
        Without the widget, the signal returns null (no penalty for no-JS visitors).
      </p>

      <h3>Submission rate</h3>
      <p>
        More than ten submissions from the same IP to the same form in 60 seconds gets +25 points.
        This is on top of the per-IP rate-limit middleware — the limit catches floods, the signal
        catches sustained-low-rate scraping.
      </p>

      <h3>Content</h3>
      <p>
        Heuristic checks on the longest free-text field — URL density (≥ 3 = +10), all-caps ratio
        (&gt; 0.5 = +5), phone-number density (≥ 3 = +5), very-short body (&lt; 6 chars = +5).
        Capped at +25.
      </p>

      <h3>Email validity</h3>
      <p>
        RFC 5322 syntactic check (+15 on fail) + disposable-domain blacklist (+10). The blacklist
        covers the major throwaway services (10minutemail, mailinator, guerrillamail, …) — extend
        as a v2 enhancement.
      </p>

      <h3>Captcha (optional)</h3>
      <p>
        Pass-through to Cloudflare Turnstile (or compatible). If a visitor submits a token that
        verifies, the signal contributes 0; if it fails, +10 on top of whatever else fired.
      </p>

      <h2>Graduated decision</h2>
      <p>
        Score is mapped to a state by the form's threshold (default 50):
      </p>
      <ul>
        <li><strong>0–29</strong> → <em>clean</em> — accepted, destinations dispatched immediately.</li>
        <li><strong>30–49</strong> → <em>quarantined</em> — accepted but flagged. Visitor sees a captcha challenge; on pass → clean; on fail or timeout → spam.</li>
        <li><strong>50+</strong> → <em>spam</em> — stored, no destinations dispatched, visible in admin's Spam tab.</li>
        <li><strong>Hard-block</strong> (honeypot or blocklisted IP) → <em>rejected</em> — no payload stored, only metadata.</li>
      </ul>

      <h2>Why the breakdown is public</h2>
      <p>
        Black-box scoring breeds frustrated buyers — when a real customer's email is wrongly flagged,
        the buyer has no recourse without the signal-level detail. Inkwell exposes the full
        breakdown verbatim in the admin and (for the live demo) on the public result page. Buyers
        see the explainability, tune their threshold or per-form weights, and trust the system.
      </p>

      <h2>Adding a signal</h2>
      <p>
        Implement <code>App\Services\Spam\SpamSignal</code>, register the class in{' '}
        <code>config/inkwell.php</code>, add a corpus row in{' '}
        <code>tests/corpus/spam-corpus.json</code>, ship. The corpus is the regression contract —
        change semantics? Update the corpus first.
      </p>
    </article>
  );
}
