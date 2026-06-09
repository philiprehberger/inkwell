'use client';

import { useState } from 'react';

export default function LiveDemoPage() {
  const [submitted, setSubmitted] = useState<null | { state: string; id: string; score?: number }>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function handle(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const form = e.currentTarget;
    const data = new FormData(form);
    const body = Object.fromEntries(data.entries()) as Record<string, string>;
    try {
      const res = await fetch('/api/demo-submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.detail || 'submission failed');
      setSubmitted(json);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'unknown error');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-8 pb-20">
      <header>
        <h1 className="text-3xl font-bold tracking-tight">Live demo</h1>
        <p className="mt-3 max-w-3xl text-(--color-ink-dim)">
          Fill the form. Submissions are public — they appear briefly in a redacted feed below for
          inspection, then auto-purge after one hour. The signal breakdown is shown verbatim so you
          can see <em>why</em> the spam scorer made its call.
        </p>
      </header>

      <div className="grid gap-8 lg:grid-cols-2">
        <section className="rounded-lg border border-(--color-paper-dim) bg-white p-6">
          <h2 className="text-lg font-semibold">Contact form</h2>
          <form className="mt-6 space-y-4" onSubmit={handle}>
            <Field label="Name" name="name" required />
            <Field label="Email" name="email" type="email" required />
            <Textarea label="Message" name="message" required />
            <input type="text" name="_subject_honeypot" style={{ display: 'none' }} tabIndex={-1} autoComplete="off" />
            <button
              type="submit"
              disabled={busy}
              className="rounded-md bg-(--color-ink) px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
            >
              {busy ? 'Submitting…' : 'Send'}
            </button>
          </form>
        </section>

        <section className="rounded-lg border border-(--color-paper-dim) bg-white p-6">
          <h2 className="text-lg font-semibold">Result</h2>
          {error && <p className="mt-4 text-(--color-bad)">{error}</p>}
          {!submitted && !error && (
            <p className="mt-4 text-(--color-ink-dim)">Submit the form to see the result + signal breakdown render here.</p>
          )}
          {submitted && (
            <div className="mt-4 space-y-3">
              <div className="text-sm">
                <span className="font-semibold">State:</span>{' '}
                <span
                  className={
                    submitted.state === 'clean'
                      ? 'rounded bg-emerald-100 px-2 py-0.5 text-emerald-700'
                      : submitted.state === 'spam'
                        ? 'rounded bg-red-100 px-2 py-0.5 text-red-700'
                        : 'rounded bg-amber-100 px-2 py-0.5 text-amber-700'
                  }
                >
                  {submitted.state}
                </span>
              </div>
              <div className="text-sm">
                <span className="font-semibold">Submission ID:</span>{' '}
                <code className="text-xs">{submitted.id}</code>
              </div>
              {submitted.score !== undefined && (
                <div className="text-sm">
                  <span className="font-semibold">Spam score:</span> {submitted.score}/100
                </div>
              )}
            </div>
          )}

          <h3 className="mt-8 text-sm font-semibold uppercase tracking-wider text-(--color-ink-dim)">
            Try a spammy submission
          </h3>
          <p className="mt-2 text-sm text-(--color-ink-dim)">
            Fill the message with three URLs (
            <code>https://a.com https://b.com https://c.com</code>) and a disposable email
            (<code>x@10minutemail.com</code>) — watch the score climb above the 50-point threshold.
            Or fill the hidden honeypot field via DevTools and observe a hard-block.
          </p>
        </section>
      </div>
    </div>
  );
}

function Field({ label, name, type = 'text', required = false }: { label: string; name: string; type?: string; required?: boolean }) {
  return (
    <label className="block text-sm">
      <span className="font-medium">{label}</span>
      <input
        type={type}
        name={name}
        required={required}
        className="mt-1 block w-full rounded border border-(--color-paper-dim) bg-(--color-paper) px-3 py-2 font-mono text-sm"
      />
    </label>
  );
}

function Textarea({ label, name, required = false }: { label: string; name: string; required?: boolean }) {
  return (
    <label className="block text-sm">
      <span className="font-medium">{label}</span>
      <textarea
        name={name}
        required={required}
        rows={4}
        className="mt-1 block w-full rounded border border-(--color-paper-dim) bg-(--color-paper) px-3 py-2 font-mono text-sm"
      />
    </label>
  );
}
