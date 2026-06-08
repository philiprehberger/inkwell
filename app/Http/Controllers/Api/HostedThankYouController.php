<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HostedThankYouController extends Controller
{
    public function __invoke(Request $request, string $id): Response
    {
        // Minimal HTML thank-you page. Phase 7's docs site replaces this with a
        // themeable per-workspace branded page.
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Thank you — Inkwell</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; padding: 0;
           min-height: 100vh; display: grid; place-items: center; background: #f7f6f3; color: #0f1015; }
    main { text-align: center; padding: 2rem; max-width: 32rem; }
    h1 { font-size: 1.5rem; margin: 0 0 0.5rem; }
    p  { color: #404252; line-height: 1.5; margin: 0 0 1rem; }
    .small { color: #6b7280; font-size: 0.85rem; }
  </style>
</head>
<body>
  <main>
    <h1>Thanks — we got it.</h1>
    <p>Your submission was received and is being processed. You can close this tab.</p>
    <p class="small">Powered by Inkwell.</p>
  </main>
</body>
</html>
HTML;
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
