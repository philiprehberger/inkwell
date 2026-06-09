import { NextResponse } from 'next/server';

/**
 * Demo proxy that posts to the live Inkwell sandbox form.
 * In production this passes through to the API; the sandbox form is configured
 * via INKWELL_DEMO_FORM_ID at deploy time.
 */
export async function POST(request: Request) {
  const formId = process.env.INKWELL_DEMO_FORM_ID;
  const apiBase = process.env.NEXT_PUBLIC_API_BASE || 'https://api.inkwell.philiprehberger.com';

  if (!formId) {
    return NextResponse.json(
      { state: 'demo-unconfigured', id: 'n/a', score: 0 },
      { status: 200 },
    );
  }

  const body = await request.json();
  const res = await fetch(`${apiBase}/v1/forms/${formId}/submit`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Origin: 'https://inkwell.philiprehberger.com' },
    body: JSON.stringify(body),
  });
  const json = await res.json();
  return NextResponse.json(json, { status: res.status });
}
