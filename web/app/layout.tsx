import './globals.css';
import Link from 'next/link';
import type { ReactNode } from 'react';

export const metadata = {
  title: 'Inkwell — form submission API with explainable spam scoring',
  description:
    'Drop one HTML form tag onto your site. Inkwell catches submissions, scores them with a visible signal breakdown, and forwards to email + webhook + Slack + Discord + Google Sheets + HubSpot + Mailchimp.',
  metadataBase: new URL('https://inkwell.philiprehberger.com'),
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <body>
        <header className="border-b border-(--color-paper-dim) bg-(--color-paper)">
          <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
            <Link href="/" className="text-lg font-bold tracking-tight text-(--color-ink) no-underline">
              Inkwell
              <span className="ml-2 rounded-full bg-(--color-accent-soft) px-2 py-0.5 text-xs font-medium text-(--color-accent) align-middle">
                portfolio demo
              </span>
            </Link>
            <nav className="flex items-center gap-6 text-sm text-(--color-ink-dim)">
              <Link href="/live-demo">Live demo</Link>
              <Link href="/reference">Reference</Link>
              <Link href="/concepts/spam-scoring">Spam scoring</Link>
              <Link href="/destinations">Destinations</Link>
              <Link href="/widget">Widget</Link>
              <Link href="/about">About</Link>
            </nav>
          </div>
        </header>
        <main className="mx-auto max-w-6xl px-6 py-10">{children}</main>
        <footer className="border-t border-(--color-paper-dim) mt-20">
          <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-8 text-sm text-(--color-ink-dim)">
            <div>
              Inkwell is a portfolio demonstration by{' '}
              <a href="https://philiprehberger.com">Philip Rehberger</a>.
            </div>
            <div className="flex gap-4">
              <a href="https://github.com/philiprehberger/inkwell">GitHub</a>
              <Link href="/legal/dpa">DPA</Link>
              <Link href="/about">About</Link>
            </div>
          </div>
        </footer>
      </body>
    </html>
  );
}
