<?php

namespace Tests\Feature;

use App\Models\FormDestination;
use App\Models\Submission;
use App\Services\Destinations\WebhookDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
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

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response(['ok' => true], 200);
        });

        // Exercise the REAL delivery path. An earlier version of this test
        // rebuilt the body by hand and called sign() through reflection, which
        // would have passed even if deliver() were broken — the opposite of
        // what a regression oracle is for.
        (new WebhookDestination)->deliver($this->submission(), $this->destination($rotating));

        $this->assertNotNull($captured, 'no request was transmitted');
        $this->assertSame($golden['body'], $captured->body(), 'delivery body drifted from the golden');

        foreach ($golden['headers'] as $header => $expected) {
            $this->assertSame(
                $expected,
                $captured->header($header)[0] ?? null,
                "header [{$header}] drifted from the golden",
            );
        }

        if (! $rotating) {
            $this->assertEmpty(
                $captured->header('X-Inkwell-Signature-Old'),
                'a non-rotating destination must not emit a rotation header',
            );
        }
    }

    private function submission(): Submission
    {
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

        return $submission;
    }

    private function destination(bool $rotating): FormDestination
    {
        $destination = new FormDestination;
        $destination->id = '01JGOLDEN0DESTINATION00001';
        $destination->form_id = '01JGOLDEN0FORM000000000001';
        $destination->kind = FormDestination::KIND_WEBHOOK;
        $destination->config = [
            'url' => 'https://example.com/hook',
            'secret' => self::SECRET,
        ];

        if ($rotating) {
            $destination->previous_secret = self::PREVIOUS_SECRET;
            $destination->previous_secret_expires_at = CarbonImmutable::parse(self::FROZEN_TIME)->addDay();
        }

        return $destination;
    }
}
