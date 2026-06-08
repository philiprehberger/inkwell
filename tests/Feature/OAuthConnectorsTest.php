<?php

namespace Tests\Feature;

use App\Models\FormDestination;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Services\Destinations\GoogleSheetsDestination;
use App\Services\Destinations\HubSpotDestination;
use App\Services\Destinations\MailchimpDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthConnectorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_sheets_validates_config(): void
    {
        $d = new GoogleSheetsDestination;
        $this->assertFalse($d->validateConfig([])->valid);
        $this->assertFalse($d->validateConfig(['spreadsheet_id' => 'X'])->valid);
        $this->assertTrue($d->validateConfig([
            'spreadsheet_id' => 'X', 'sheet_name' => 'Sheet1', 'access_token' => 'token',
        ])->valid);
    }

    public function test_google_sheets_appends_row(): void
    {
        Http::fake(['*sheets.googleapis.com*' => Http::response('', 200)]);
        [$dest, $submission] = $this->setupDestinationAndSubmission(FormDestination::KIND_GOOGLE_SHEETS, [
            'spreadsheet_id' => 'spreadsheet-X', 'sheet_name' => 'Sheet1', 'access_token' => 't0ken',
        ]);

        $result = (new GoogleSheetsDestination)->deliver($submission, $dest);
        $this->assertTrue($result->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'spreadsheet-X'));
    }

    public function test_google_sheets_oauth_expired(): void
    {
        Http::fake(['*sheets.googleapis.com*' => Http::response('', 401)]);
        [$dest, $submission] = $this->setupDestinationAndSubmission(FormDestination::KIND_GOOGLE_SHEETS, [
            'spreadsheet_id' => 'X', 'sheet_name' => 'Sheet1', 'access_token' => 't0ken',
        ]);

        $result = (new GoogleSheetsDestination)->deliver($submission, $dest);
        $this->assertFalse($result->success);
        $this->assertSame('oauth_expired', $result->errorCode);
    }

    public function test_hubspot_validates_config(): void
    {
        $d = new HubSpotDestination;
        $this->assertFalse($d->validateConfig([])->valid);
        $this->assertTrue($d->validateConfig(['access_token' => 'tok'])->valid);
    }

    public function test_hubspot_refuses_submission_without_email(): void
    {
        [$dest, $submission] = $this->setupDestinationAndSubmission(FormDestination::KIND_HUBSPOT, [
            'access_token' => 'tok',
        ], ['name' => 'Anonymous']);

        $result = (new HubSpotDestination)->deliver($submission, $dest);
        $this->assertFalse($result->success);
        $this->assertSame('missing_email', $result->errorCode);
    }

    public function test_mailchimp_validates_api_key_format(): void
    {
        $d = new MailchimpDestination;
        $this->assertFalse($d->validateConfig(['api_key' => 'no-dash'])->valid);
        $this->assertFalse($d->validateConfig(['api_key' => 'abc-us21'])->valid); // missing audience_id
        $this->assertTrue($d->validateConfig(['api_key' => 'abc-us21', 'audience_id' => 'aud'])->valid);
    }

    public function test_mailchimp_targets_correct_datacenter(): void
    {
        Http::fake(['*us21.api.mailchimp.com*' => Http::response('', 200)]);
        [$dest, $submission] = $this->setupDestinationAndSubmission(FormDestination::KIND_MAILCHIMP, [
            'api_key' => 'key-us21', 'audience_id' => 'aud-123',
        ]);

        $result = (new MailchimpDestination)->deliver($submission, $dest);
        $this->assertTrue($result->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'us21.api.mailchimp.com') && str_contains($r->url(), 'aud-123'));
    }

    /**
     * @return array{0: FormDestination, 1: Submission}
     */
    private function setupDestinationAndSubmission(string $kind, array $config, ?array $payload = null): array
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $destination = FormDestination::create([
            'form_id' => $form->id,
            'kind' => $kind,
            'config' => $config,
            'enabled' => true,
        ]);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => $payload ?? ['name' => 'A', 'email' => 'a@example.com', 'message' => 'M'],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'abc',
        ]);
        return [$destination, $submission];
    }
}
