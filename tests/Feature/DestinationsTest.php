<?php

namespace Tests\Feature;

use App\Jobs\DeliverToDestinationJob;
use App\Jobs\DispatchDestinationsJob;
use App\Models\Delivery;
use App\Models\Form;
use App\Models\FormDestination;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Services\Destinations\DestinationRegistry;
use App\Services\Destinations\EmailDestination;
use App\Services\Destinations\Header;
use App\Services\Destinations\SlackDestination;
use App\Services\Destinations\WebhookDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DestinationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_sanitize_strips_crlf(): void
    {
        // The security property: CRLF cannot survive into the header value.
        // Once CRLF is gone, "Bcc:" as plain text is just part of the subject
        // — SMTP won't interpret it as a header without a preceding newline.
        $dirty = "Subject\r\nBcc: attacker@example.com";
        $clean = Header::sanitize($dirty);
        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertStringNotContainsString("\0", $clean);
    }

    public function test_email_destination_validates_config(): void
    {
        $d = new EmailDestination;
        $this->assertFalse($d->validateConfig([])->valid);
        $this->assertFalse($d->validateConfig(['to' => 'not-an-array'])->valid);
        $this->assertFalse($d->validateConfig(['to' => ['bad-address']])->valid);
        $this->assertTrue($d->validateConfig(['to' => ['ok@example.com']])->valid);
    }

    public function test_email_destination_delivers(): void
    {
        Mail::fake();

        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $destination = FormDestination::create([
            'form_id' => $form->id,
            'kind' => FormDestination::KIND_EMAIL,
            'config' => ['to' => ['ops@example.com']],
            'enabled' => true,
        ]);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['name' => 'A', 'email' => 'a@example.com', 'message' => 'M'],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'abc',
        ]);

        $result = (new EmailDestination)->deliver($submission, $destination);
        $this->assertTrue($result->success);
        // Mail::fake() intercepts the send; success without exception is the assertion.
    }

    public function test_webhook_destination_signs_request(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);

        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $destination = FormDestination::create([
            'form_id' => $form->id,
            'kind' => FormDestination::KIND_WEBHOOK,
            'config' => ['url' => 'https://example.com/inkwell', 'secret' => 'super-secret-please-keep-secret'],
            'enabled' => true,
        ]);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['name' => 'A'],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'abc',
        ]);

        $result = (new WebhookDestination)->deliver($submission, $destination);
        $this->assertTrue($result->success);
        Http::assertSent(function ($request) {
            $sig = $request->header('X-Inkwell-Signature');
            return $sig !== [] && str_starts_with($sig[0] ?? '', 't=');
        });
    }

    public function test_webhook_destination_refuses_private_ip(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $destination = FormDestination::create([
            'form_id' => $form->id,
            'kind' => FormDestination::KIND_WEBHOOK,
            'config' => ['url' => 'http://127.0.0.1/internal', 'secret' => 'super-secret-please-keep-secret'],
            'enabled' => true,
        ]);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => [],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'abc',
        ]);

        $result = (new WebhookDestination)->deliver($submission, $destination);
        $this->assertFalse($result->success);
        $this->assertSame('ssrf_blocked', $result->errorCode);
    }

    public function test_slack_destination_validates_url_shape(): void
    {
        $d = new SlackDestination;
        $this->assertFalse($d->validateConfig(['webhook_url' => 'https://example.com'])->valid);
        $this->assertTrue($d->validateConfig(['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXX'])->valid);
    }

    public function test_destination_registry_resolves_by_kind(): void
    {
        $registry = DestinationRegistry::fromConfig();
        $this->assertInstanceOf(EmailDestination::class, $registry->get(FormDestination::KIND_EMAIL));
        $this->assertInstanceOf(WebhookDestination::class, $registry->get(FormDestination::KIND_WEBHOOK));
        $this->assertInstanceOf(SlackDestination::class, $registry->get(FormDestination::KIND_SLACK));
    }

    public function test_clean_ingest_dispatches_fan_out_job(): void
    {
        Queue::fake();
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'A', 'email' => 'a@example.com', 'message' => 'Hello there from a real human submitter',
        ], ['Origin' => 'https://example.com'])->assertOk();

        Queue::assertPushed(DispatchDestinationsJob::class);
    }

    public function test_dispatch_creates_one_delivery_per_destination(): void
    {
        Queue::fake();
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $form->destinations()->createMany([
            ['kind' => FormDestination::KIND_EMAIL, 'config' => ['to' => ['a@example.com']], 'enabled' => true],
            ['kind' => FormDestination::KIND_WEBHOOK, 'config' => ['url' => 'https://example.com/hook', 'secret' => 'short-secret-1234'], 'enabled' => true],
        ]);

        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['name' => 'A'],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'abc',
        ]);

        (new DispatchDestinationsJob($submission->id))->handle();

        $this->assertSame(2, Delivery::where('submission_id', $submission->id)->count());
        Queue::assertPushed(DeliverToDestinationJob::class, 2);
    }
}
