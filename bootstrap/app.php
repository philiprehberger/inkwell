<?php

use App\Http\Responses\ProblemResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
            'workspace.rate-limit' => \App\Http\Middleware\WorkspaceRateLimit::class,
            'idempotency' => \App\Http\Middleware\IdempotencyKey::class,
        ]);

        // Trust only the loopback Apache in front of php-fpm.
        //
        // This previously read `at: '*'`, with a comment claiming Cloudflare
        // ranges refreshed by a command that was never written. Nothing fronts
        // this host: PHP is served through mod_proxy_fcgi, mod_remoteip is not
        // enabled, and no RemoteIPHeader is configured — so REMOTE_ADDR is
        // already the true client address. Trusting every proxy meant Laravel
        // preferred a client-supplied X-Forwarded-For over it, which made the
        // per-IP ingest rate limit, IpReputationSignal and SubmissionRateSignal
        // all bypassable with one header on the unauthenticated endpoint.
        //
        // If a CDN is introduced, add its ranges here at cutover.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // Plan 5.6 — accept and echo W3C trace context on every request, so a
        // submission joins the caller's trace rather than starting its own.
        // Queue-boundary propagation is registered by the package's service
        // provider; this is the HTTP half.
        $middleware->append(\PhilipRehberger\Interchange\Http\TraceMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        // Validation errors get a 400 problem+json with field-level errors.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }

            return new ProblemResponse(
                status: 400,
                title: 'Invalid request',
                detail: 'The request body failed validation.',
                errors: $e->errors(),
            );
        });

        // Everything else maps via ProblemResponse::for.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }

            return ProblemResponse::for($e);
        });
    })->create();
