#!/usr/bin/env node
//
// Generate a PDF of the live Scalar reference page and drop it in
// web/public/. Run after the docs site is deployed so the live HTML
// reflects the current spec.
//
// Usage: node scripts/build-reference-pdf.js [version=0.4.0]

const path = require('path');
const puppeteer = require('puppeteer');

const VERSION = process.argv[2] || '0.4.0';
const URL = 'https://webhook-relay.dcsuniverse.com/reference';
const OUT = path.resolve(
  __dirname,
  '..',
  'web',
  'public',
  `openapi-reference-${VERSION}.pdf`,
);

(async () => {
  // Reuse the Chrome binary that the screenshot pipeline already has cached.
  const chromePath = process.env.PUPPETEER_EXECUTABLE_PATH
    || '/home/ubuntu/.cache/puppeteer/chrome/linux-148.0.7778.97/chrome-linux64/chrome';

  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: chromePath,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.emulateMediaType('print');
    await page.goto(URL, { waitUntil: 'networkidle2', timeout: 90000 });

    // Scalar renders client-side after fetching /openapi.yaml. Wait for the
    // reference to settle by polling for the operation count to stabilize.
    await page.waitForFunction(
      () => document.querySelectorAll('.scalar-api-reference').length > 0
        || document.querySelectorAll('[data-scalar-api-reference]').length > 0
        || document.querySelectorAll('[class*="references-rendered"]').length > 0,
      { timeout: 60000 },
    ).catch(() => null);
    // Extra settle for late-loading code samples.
    await new Promise((r) => setTimeout(r, 5000));

    await page.pdf({
      path: OUT,
      format: 'Letter',
      printBackground: true,
      margin: { top: '0.6in', right: '0.6in', bottom: '0.8in', left: '0.6in' },
      displayHeaderFooter: true,
      headerTemplate: `<div style="font-size:8px;width:100%;padding:0 0.6in;color:#9ca3af;">Webhook Relay API Reference — v${VERSION}</div>`,
      footerTemplate: '<div style="font-size:8px;width:100%;padding:0 0.6in;text-align:right;color:#9ca3af;"><span class="pageNumber"></span> / <span class="totalPages"></span></div>',
    });

    console.log(`✓ ${OUT}`);
  } finally {
    await browser.close();
  }
})();
