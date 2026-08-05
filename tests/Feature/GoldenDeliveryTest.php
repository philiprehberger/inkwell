<?php

namespace Tests\Feature;

use App\Models\FormDestination;
use App\Models\Submission;
use App\Services\Destinations\WebhookDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Plan T-5.2 — D-3 byte-identical regression.
 *
 * These goldens were captured BEFORE the Interchange adoption. Any change to
 * signing or envelope construction that alters a single byte of what an
 * existing destination receives will fail here.
 *
 * The point is that existing consumers hold secrets and verify signatures. A
 * change they did not agree to is a broken integration, not a refactor.
 */
class GoldenDeliveryTest extends TestCase
{
    private const FROZEN_TIME = '2026-01-15T12:00:00+00:00';

    private const SECRET = 'golden-test-secret-not-a-real-one';

    private const PREVIOUS_SECRET = 'golden-previous-secret-rotating';

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_default_webhook_delivery_is_byte_identical_to_the_golden(): void
    {
        $this->assertMatchesGolden('webhook-default', rotating: false);
    }

    public function test_rotating_secret_delivery_is_byte_identical_to_the_golden(): void
    {
        $this->assertMatchesGolden('webhook-rotating-secret', rotating: true);
    }

    private function assertMatchesGolden(string $name, bool $rotating): void
    {
        Date::setTestNow(CarbonImmutable::parse(self::FROZEN_TIME));

        $golden = json_decode(file_get_contents(base_path("tests/goldens/{$name}.json")), true);

        $submission = new Submission;
        $submission->id = '01JGOLDEN0SUBMISSION000001';
        $submission->form_id = '01JGOLDEN0FORM000000000001';
        $submission->state = 'clean';
        $submission->spam_score = 0.02;
        $submission->payload = [
            'name' => 'Jane Doe',
            'email' => 'jane.doe@acme.example.test',
            'message' => 'Looking for help modernising a CodeIgniter app.',
        ];
        $submission->meta = ['ip' => '203.0.113.10', 'user_agent' => 'golden/1.0'];
        $submission->created_at = CarbonImmutable::parse(self::FROZEN_TIME);

        $body = json_encode([
            'id' => $submission->id,
            'form_id' => $submission->form_id,
            'state' => $submission->state,
            'spam_score' => $submission->spam_score,
            'payload' => $submission->payload,
            'meta' => $submission->meta,
            'created_at' => $submission->created_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        $this->assertSame($golden['body'], $body, 'delivery body drifted from the golden');

        $timestamp = CarbonImmutable::parse(self::FROZEN_TIME)->getTimestamp();
        $sign = new ReflectionMethod(WebhookDestination::class, 'sign');
        $sign->setAccessible(true);
        $instance = new WebhookDestination;

        $this->assertSame(
            $golden['headers']['X-Inkwell-Signature'],
            't='.$timestamp.',v1='.$sign->invoke($instance, $timestamp, $body, self::SECRET),
            'inkwell-v0 signature drifted from the golden',
        );

        if ($rotating) {
            $this->assertSame(
                $golden['headers']['X-Inkwell-Signature-Old'],
                't='.$timestamp.',v1='.$sign->invoke($instance, $timestamp, $body, self::PREVIOUS_SECRET),
                'rotation signature drifted from the golden',
            );
        }
    }
}
