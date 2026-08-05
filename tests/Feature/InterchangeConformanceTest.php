<?php

namespace Tests\Feature;

use App\Conformance\InkwellConformanceHarness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhilipRehberger\Interchange\Conformance\ConformanceHarness;
use PhilipRehberger\Interchange\Signing\StandardWebhooksScheme;
use PhilipRehberger\Interchange\Tracing\TraceContext;
use PhilipRehberger\InterchangeConformance\ConformanceSuite;
use Tests\TestCase;

/**
 * Plan T-5.3 — hand-verification against SPEC.md.
 *
 * The shared conformance suite lands in a later phase (6.8); until then these
 * assert the same requirement keys locally, so Inkwell is not taken on trust
 * between now and then.
 */
class InterchangeConformanceTest extends TestCase
{
    use RefreshDatabase;

    private ConformanceHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new InkwellConformanceHarness;
    }

    /** SPEC §8 — trace.http, trace.http.echo */
    public function test_trace_http_accepts_and_echoes_context(): void
    {
        $context = TraceContext::start();

        $response = $this->withHeaders([
            'traceparent' => $context->toTraceparent(),
        ])->getJson($this->harness->inboundTracePath());

        $echoed = $response->headers->get('traceparent');

        $this->assertNotNull($echoed, 'no traceparent echoed');
        $this->assertStringContainsString($context->traceId, $echoed, 'trace-id must be preserved');
        $this->assertStringNotContainsString(
            $context->parentId,
            $echoed,
            'the response must carry OUR span, not replay the caller\'s',
        );
    }

    /** A request without context gets a valid trace rather than a broken one. */
    public function test_trace_http_mints_a_trace_when_none_is_supplied(): void
    {
        $echoed = $this->getJson($this->harness->inboundTracePath())->headers->get('traceparent');

        $this->assertNotNull($echoed);
        $parsed = TraceContext::parse($echoed);
        $this->assertNotNull($parsed, 'the minted traceparent must itself be valid');
    }

    /** A malformed inbound header must not propagate as if it were a trace. */
    public function test_trace_http_replaces_a_malformed_context(): void
    {
        $echoed = $this->withHeaders(['traceparent' => 'not-a-traceparent'])
            ->getJson($this->harness->inboundTracePath())
            ->headers->get('traceparent');

        $this->assertNotNull(TraceContext::parse($echoed));
    }

    /** SPEC §8 — sig.standard-webhooks.sign + sig.secret.decode */
    public function test_sig_standard_webhooks_round_trips(): void
    {
        $result = $this->harness->triggerSignedDelivery('standard-webhooks');

        $this->assertArrayHasKey('webhook-id', $result['headers']);
        $this->assertArrayHasKey('webhook-timestamp', $result['headers']);
        $this->assertStringStartsWith('v1,', $result['headers']['webhook-signature']);

        $this->assertTrue(
            (new StandardWebhooksScheme)->verify($result['headers'], $result['body'], $result['secret']),
            'a delivery this service signed must verify with the package',
        );
    }

    /** The native scheme is still selectable and unchanged. */
    public function test_the_native_scheme_remains_available(): void
    {
        $result = $this->harness->triggerSignedDelivery('inkwell-v0');

        $this->assertStringStartsWith('t=', $result['headers']['x-inkwell-signature'] ?? '');
        $this->assertArrayNotHasKey('webhook-signature', $result['headers']);
    }

    /**
     * The shared suite, run against Inkwell's own harness. This is the
     * assertion set every service in the fleet is measured by — the same
     * package, the same requirement keys, only the adapter differs.
     */
    public function test_the_shared_conformance_suite_passes(): void
    {
        $report = (new ConformanceSuite($this->harness))->run();

        $this->assertTrue($report->isConformant(), $report->toMatrix());
        $this->assertSame('pass', $report->results()['sig.standard-webhooks.sign']['state']);
        $this->assertSame('pass', $report->results()['sig.standard-webhooks.verify']['state']);
        $this->assertSame('pass', $report->results()['sig.secret.decode']['state']);
    }

    public function test_the_conformance_report_is_machine_readable_for_the_dashboard(): void
    {
        $decoded = json_decode((new ConformanceSuite($this->harness))->run()->toJson(), true);

        $this->assertSame('inkwell', $decoded['service']);
        $this->assertSame('ci', $decoded['source']);
        $this->assertTrue($decoded['conformant']);
    }

    public function test_the_harness_declares_both_schemes(): void
    {
        $this->assertSame(['inkwell-v0', 'standard-webhooks'], $this->harness->supportedSchemes());
        $this->assertSame('inkwell', $this->harness->serviceSlug());
    }
}
