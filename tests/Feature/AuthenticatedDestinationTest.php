<?php

namespace Tests\Feature;

use App\Models\FormDestination;
use App\Models\Submission;
use App\Models\Workspace;
use App\Services\Destinations\EnvelopeShape;
use App\Services\Destinations\Security\EgressPolicy;
use App\Services\Destinations\Security\HeaderPolicy;
use App\Services\Destinations\WebhookDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PhilipRehberger\Interchange\Signing\StandardWebhooksScheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan T-5.1 — authenticated destinations, envelope shapes, scheme selection.
 * Threat model: docs/security/authenticated-destinations.md
 */
class AuthenticatedDestinationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'golden-test-secret-not-a-real-one';

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- headers

    public function test_allowlisted_headers_are_accepted(): void
    {
        $errors = (new HeaderPolicy)->validate([
            'Authorization' => 'Bearer abc',
            'X-Api-Key' => 'k',
            'Idempotency-Key' => 'i',
            'X-Custom-Thing' => 'v',
        ]);

        $this->assertSame([], $errors);
    }

    public function test_dangerous_headers_are_blocked_even_though_they_look_ordinary(): void
    {
        $policy = new HeaderPolicy;

        foreach (['Host', 'Cookie', 'Content-Length', 'Transfer-Encoding', 'Connection', 'Proxy-Authorization'] as $name) {
            $this->assertFalse($policy->isAllowed($name), "[{$name}] must be blocked");
            $this->assertNotSame([], $policy->validate([$name => 'x']));
        }
    }

    public function test_the_block_survives_unusual_casing(): void
    {
        $policy = new HeaderPolicy;

        $this->assertFalse($policy->isAllowed('HOST'));
        $this->assertFalse($policy->isAllowed('CoOkIe'));
    }

    public function test_header_injection_via_crlf_is_rejected(): void
    {
        $errors = (new HeaderPolicy)->validate([
            'X-Thing' => "value\r\nX-Injected: evil",
        ]);

        $this->assertNotSame([], $errors);
    }

    public function test_header_count_and_size_are_bounded(): void
    {
        $policy = new HeaderPolicy;

        $many = [];
        for ($i = 0; $i < 20; $i++) {
            $many["X-H{$i}"] = 'v';
        }
        $this->assertNotSame([], $policy->validate($many));

        $this->assertNotSame([], $policy->validate(['X-Big' => str_repeat('x', 5000)]));
    }

    public function test_credential_values_are_redacted_and_never_returned(): void
    {
        $redacted = (new HeaderPolicy)->redact([
            'Authorization' => 'Bearer super-secret',
            'X-Signature' => 'sig',
            'Accept' => 'application/json',
        ]);

        $this->assertSame('••••••••', $redacted['Authorization']);
        $this->assertSame('••••••••', $redacted['X-Signature']);
        $this->assertSame('application/json', $redacted['Accept'], 'non-secret headers stay readable');
    }

    // ----------------------------------------------------------------- egress

    public function test_a_credentialed_destination_requires_an_egress_allowlist(): void
    {
        $workspace = new Workspace;
        $workspace->egress_allowlist = null;

        $errors = (new EgressPolicy)->validate($workspace, 'https://api.example.com/hook', [
            'Authorization' => 'Bearer x',
        ]);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('egress allowlist', $errors[0]);
    }

    public function test_an_uncredentialed_destination_is_unaffected(): void
    {
        // Every existing destination is in this category — behaviour unchanged.
        $workspace = new Workspace;
        $workspace->egress_allowlist = null;

        $this->assertSame([], (new EgressPolicy)->validate($workspace, 'https://anywhere.example.com/hook', []));
    }

    public function test_the_allowlist_matches_host_and_subdomains_only(): void
    {
        $workspace = new Workspace;
        $workspace->egress_allowlist = ['example.com'];
        $policy = new EgressPolicy;
        $creds = ['Authorization' => 'Bearer x'];

        $this->assertSame([], $policy->validate($workspace, 'https://example.com/h', $creds));
        $this->assertSame([], $policy->validate($workspace, 'https://api.example.com/h', $creds));
        $this->assertNotSame([], $policy->validate($workspace, 'https://evil.com/h', $creds));
        $this->assertNotSame([], $policy->validate($workspace, 'https://notexample.com/h', $creds));
    }

    // -------------------------------------------------------------- envelopes

    public function test_the_webhook_relay_shape_matches_what_that_service_requires(): void
    {
        // The whole reason envelope shapes exist: Webhook Relay's ingest
        // demands {type, payload} and Inkwell could not produce it.
        $body = EnvelopeShape::WebhookRelay->build($this->submission());

        $this->assertSame(['type', 'payload'], array_keys($body));
        $this->assertSame('form.submission.created', $body['type']);
        $this->assertSame('01JGOLDEN0SUBMISSION000001', $body['payload']['id']);
    }

    public function test_the_flat_shape_is_the_payload_alone(): void
    {
        $this->assertSame(
            ['name' => 'Jane Doe', 'email' => 'jane.doe@acme.example.test'],
            EnvelopeShape::Flat->build($this->submission()),
        );
    }

    public function test_an_unknown_shape_is_rejected_at_validation(): void
    {
        $result = (new WebhookDestination)->validateConfig([
            'url' => 'https://example.com/hook',
            'secret' => self::SECRET,
            'envelope_shape' => 'arbitrary-template',
        ]);

        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('envelope_shape', $result->errors);
    }

    // ---------------------------------------------------------------- signing

    public function test_selecting_standard_webhooks_emits_the_spec_headers(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-01-15T12:00:00+00:00'));
        $captured = $this->deliverWith(fn (FormDestination $d) => $d->signature_scheme = 'standard-webhooks');

        $this->assertNotEmpty($captured->header('webhook-id'));
        $this->assertNotEmpty($captured->header('webhook-timestamp'));
        $this->assertStringStartsWith('v1,', $captured->header('webhook-signature')[0]);

        // The native header must be absent — this is a different scheme, not
        // both at once.
        $this->assertEmpty($captured->header('X-Inkwell-Signature'));
    }

    public function test_a_standard_webhooks_delivery_verifies_with_the_package(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-01-15T12:00:00+00:00'));
        $secret = StandardWebhooksScheme::generateSecret();

        $captured = $this->deliverWith(function (FormDestination $d) use ($secret) {
            $d->signature_scheme = 'standard-webhooks';
            $d->config = ['url' => 'https://example.com/hook', 'secret' => $secret];
        });

        $headers = [
            'webhook-id' => $captured->header('webhook-id')[0],
            'webhook-timestamp' => $captured->header('webhook-timestamp')[0],
            'webhook-signature' => $captured->header('webhook-signature')[0],
        ];

        // Frozen clock on both sides: the delivery signed at the frozen time,
        // so the verifier must read the same clock (package v0.1.1).
        $frozen = CarbonImmutable::parse('2026-01-15T12:00:00+00:00')->getTimestamp();
        $scheme = new StandardWebhooksScheme(clock: static fn (): int => $frozen);

        $this->assertTrue($scheme->verify($headers, $captured->body(), $secret));
    }

    public function test_tenant_headers_cannot_override_a_signature(): void
    {
        // An allowlisted name colliding with ours would let a tenant strip or
        // forge the signature they are supposed to be authenticated by.
        $captured = $this->deliverWith(function (FormDestination $d) {
            $d->headers = ['X-Inkwell-Signature' => 'forged', 'Content-Type' => 'text/plain'];
        });

        $this->assertStringStartsWith('t=', $captured->header('X-Inkwell-Signature')[0]);
        $this->assertNotSame('forged', $captured->header('X-Inkwell-Signature')[0]);
        $this->assertSame('application/json', $captured->header('Content-Type')[0]);
    }

    public function test_permitted_tenant_headers_are_transmitted(): void
    {
        $captured = $this->deliverWith(function (FormDestination $d) {
            $d->headers = ['Authorization' => 'Bearer downstream-token', 'X-Tenant' => 'acme'];
        });

        $this->assertSame('Bearer downstream-token', $captured->header('Authorization')[0]);
        $this->assertSame('acme', $captured->header('X-Tenant')[0]);
    }

    public function test_forbidden_headers_are_dropped_at_send_time_not_only_at_save_time(): void
    {
        // The allowlist may tighten after a destination was created.
        $captured = $this->deliverWith(function (FormDestination $d) {
            $d->headers = ['Host' => 'evil.example.com', 'X-Fine' => 'ok'];
        });

        $this->assertNotSame('evil.example.com', $captured->header('Host')[0] ?? null);
        $this->assertSame('ok', $captured->header('X-Fine')[0]);
    }

    // ---------------------------------------------------------------- helpers

    private function deliverWith(callable $mutate)
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response(['ok' => true], 200);
        });

        $destination = new FormDestination;
        $destination->id = '01JGOLDEN0DESTINATION00001';
        $destination->form_id = '01JGOLDEN0FORM000000000001';
        $destination->kind = FormDestination::KIND_WEBHOOK;
        $destination->config = ['url' => 'https://example.com/hook', 'secret' => self::SECRET];

        $mutate($destination);

        (new WebhookDestination)->deliver($this->submission(), $destination);

        $this->assertNotNull($captured, 'no request was transmitted');

        return $captured;
    }

    private function submission(): Submission
    {
        $submission = new Submission;
        $submission->id = '01JGOLDEN0SUBMISSION000001';
        $submission->form_id = '01JGOLDEN0FORM000000000001';
        $submission->state = 'clean';
        $submission->spam_score = 0.02;
        $submission->payload = ['name' => 'Jane Doe', 'email' => 'jane.doe@acme.example.test'];
        $submission->meta = [];
        $submission->created_at = CarbonImmutable::parse('2026-01-15T12:00:00+00:00');

        return $submission;
    }
}
