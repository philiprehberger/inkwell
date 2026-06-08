<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Services\Spam\Signals\ContentSignal;
use App\Services\Spam\Signals\HoneypotSignal;
use App\Services\Spam\Signals\TimingSignal;
use App\Services\Spam\SubmissionContext;
use PHPUnit\Framework\TestCase;

class SpamSignalTest extends TestCase
{
    public function test_honeypot_signal_hard_blocks_when_filled(): void
    {
        $form = new Form(['honeypot_field' => '_subject_honeypot']);
        $form->id = 'F1';
        $ctx = $this->makeContext($form, payload: [], raw: ['_subject_honeypot' => 'bot']);
        $result = (new HoneypotSignal)->evaluate($ctx);
        $this->assertNotNull($result);
        $this->assertTrue($result->isHardBlock());
        $this->assertSame(100, $result->points);
    }

    public function test_honeypot_signal_clean_when_empty(): void
    {
        $form = new Form(['honeypot_field' => '_subject_honeypot']);
        $form->id = 'F1';
        $ctx = $this->makeContext($form, payload: [], raw: []);
        $result = (new HoneypotSignal)->evaluate($ctx);
        $this->assertSame(0, $result->points);
    }

    public function test_timing_signal_skips_without_widget_timestamp(): void
    {
        $form = new Form;
        $form->id = 'F1';
        $ctx = $this->makeContext($form, payload: [], raw: []);
        $this->assertNull((new TimingSignal)->evaluate($ctx));
    }

    public function test_timing_signal_flags_under_two_seconds(): void
    {
        $form = new Form;
        $form->id = 'F1';
        $ctx = $this->makeContext($form, payload: [], raw: ['_inkwell_ts' => time() - 1]);
        $result = (new TimingSignal)->evaluate($ctx);
        $this->assertSame(25, $result->points);
    }

    public function test_content_signal_url_density(): void
    {
        $form = new Form;
        $form->id = 'F1';
        $payload = ['message' => 'Check out https://a.com https://b.com https://c.com'];
        $ctx = $this->makeContext($form, payload: $payload, raw: $payload);
        $result = (new ContentSignal)->evaluate($ctx);
        $this->assertGreaterThan(0, $result->points);
        $this->assertSame(3, $result->metadata['url_count']);
    }

    private function makeContext(Form $form, array $payload, array $raw): SubmissionContext
    {
        return new SubmissionContext(
            form: $form,
            payload: $payload,
            raw: $raw,
            clientIp: '203.0.113.1',
            userAgent: 'test',
            referer: null,
            renderedAtTimestamp: $raw['_inkwell_ts'] ?? null,
            captchaToken: null,
        );
    }
}
