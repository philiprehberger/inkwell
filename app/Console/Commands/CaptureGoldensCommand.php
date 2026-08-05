<?php

namespace App\Console\Commands;

use App\Models\Form;
use App\Models\FormDestination;
use App\Models\Submission;
use App\Models\Workspace;
use App\Services\Destinations\WebhookDestination;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;

/**
 * Plan item 0.5.1 — D-3 output goldens.
 *
 * Renders outbound delivery bodies and signatures **deterministically**: seeded
 * test secrets, a frozen clock, fixed ids. That determinism is the whole point.
 *
 * A live capture cannot serve as a regression oracle here: a Stripe-style
 * signature incorporates the wall-clock timestamp, so replaying a captured
 * header in a test would require both the production secret and time travel.
 * Generating through this harness gives a fixture that a test can reproduce
 * exactly, which is what "byte-identical" has to mean to be checkable.
 *
 * Run BEFORE any contract change, commit the output, then diff after.
 */
class CaptureGoldensCommand extends Command
{
    protected $signature = 'inkwell:capture-goldens
                            {--path=tests/goldens : Directory to write fixtures into}';

    protected $description = 'Render deterministic delivery goldens for the webhook destination';

    /** Fixed inputs. Changing any of these invalidates every committed golden. */
    private const FROZEN_TIME = '2026-01-15T12:00:00+00:00';

    private const SECRET = 'golden-test-secret-not-a-real-one';

    private const PREVIOUS_SECRET = 'golden-previous-secret-rotating';

    private const SUBMISSION_ID = '01JGOLDEN0SUBMISSION000001';

    private const FORM_ID = '01JGOLDEN0FORM000000000001';

    public function handle(): int
    {
        Date::setTestNow(CarbonImmutable::parse(self::FROZEN_TIME));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $dir = base_path($this->option('path'));

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $goldens = [
            'webhook-default' => $this->webhook(rotating: false),
            'webhook-rotating-secret' => $this->webhook(rotating: true),
        ];

        foreach ($goldens as $name => $golden) {
            $file = $dir.'/'.$name.'.json';
            file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->line("  wrote {$name}.json");
        }

        Date::setTestNow();

        $this->newLine();
        $this->info('Goldens captured. Commit these, then diff after any signing or envelope change.');

        return self::SUCCESS;
    }

    /**
     * Build the exact body and headers WebhookDestination would transmit,
     * without a network round-trip.
     */
    private function webhook(bool $rotating): array
    {
        $submission = $this->submission();
        $destination = $this->destination($rotating);

        $body = json_encode([
            'id' => $submission->id,
            'form_id' => $submission->form_id,
            'state' => $submission->state,
            'spam_score' => $submission->spam_score,
            'payload' => $submission->payload,
            'meta' => $submission->meta,
            'created_at' => $submission->created_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = CarbonImmutable::parse(self::FROZEN_TIME)->getTimestamp();

        $sign = new ReflectionMethod(WebhookDestination::class, 'sign');
        $sign->setAccessible(true);
        $instance = new WebhookDestination;

        $headers = [
            'Content-Type' => 'application/json',
            'X-Inkwell-Signature' => 't='.$timestamp.',v1='.$sign->invoke($instance, $timestamp, $body, self::SECRET),
            'User-Agent' => 'Inkwell-Webhook/1.0',
        ];

        if ($rotating) {
            $headers['X-Inkwell-Signature-Old'] = 't='.$timestamp.',v1='
                .$sign->invoke($instance, $timestamp, $body, self::PREVIOUS_SECRET);
        }

        return [
            '_note' => 'Deterministic golden. Regenerate with `php artisan inkwell:capture-goldens`.',
            '_frozen_time' => self::FROZEN_TIME,
            'body' => $body,
            'headers' => $headers,
        ];
    }

    private function submission(): Submission
    {
        $submission = new Submission;
        $submission->id = self::SUBMISSION_ID;
        $submission->form_id = self::FORM_ID;
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
        $destination->form_id = self::FORM_ID;
        $destination->kind = FormDestination::KIND_WEBHOOK;
        $destination->config = [
            'url' => 'https://consumer.example.test/hook',
            'secret' => self::SECRET,
        ];

        if ($rotating) {
            $destination->previous_secret = self::PREVIOUS_SECRET;
            $destination->previous_secret_expires_at = CarbonImmutable::parse(self::FROZEN_TIME)->addDay();
        }

        return $destination;
    }
}
