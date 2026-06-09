export default function WidgetPage() {
  return (
    <article className="prose pb-20">
      <h1 className="text-3xl font-bold tracking-tight">The 3 KB widget</h1>
      <p>
        Strictly optional. The canonical Inkwell integration is a plain HTML form — the widget is
        progressive enhancement layered on top.
      </p>
      <p>What it adds:</p>
      <ul>
        <li>Inline error rendering on schema-validation failures (no page reload).</li>
        <li>Page-render timestamp captured in a hidden field, feeding the timing signal.</li>
        <li>Optional Turnstile / hCaptcha challenge handler for the quarantine flow.</li>
      </ul>

      <h2>Install</h2>
      <p>Add one script tag to your page, after the form:</p>
      <pre><code>{`<script src="https://inkwell.philiprehberger.com/widget/v1.js"
        integrity="sha384-…" crossorigin="anonymous" defer></script>`}</code></pre>
      <p>
        Immutable versioned URLs (<code>/widget/v1.0.0.js</code>) ship alongside the latest
        (<code>/widget/v1.js</code>) — pin the version that matches your CSP. The SRI hash for each
        version is published at <code>/widget/sri.json</code>.
      </p>

      <h2>It works without the widget too</h2>
      <p>
        Every form submission path is canonically &lt;form action="…"&gt;. The widget enhances the
        UX but never changes the contract — the server doesn't know or care whether the submission
        came through the widget or a vanilla form post.
      </p>

      <h2>Source</h2>
      <p>
        The widget lives at <code>widget/</code> in the source repo and is built into the deployed
        site's <code>/widget/</code> directory. ~3 KB gzipped.
      </p>
    </article>
  );
}
