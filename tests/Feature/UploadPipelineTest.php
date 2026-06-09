<?php

namespace Tests\Feature;

use App\Jobs\ScanUploadJob;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Services\Files\ClamScanner;
use App\Services\Files\UploadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_processor_rejects_disallowed_mime(): void
    {
        Storage::fake('local');
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace, ['allowed_mime_types' => ['image/png']]);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => [],
            'meta' => [],
            'state' => Submission::STATE_PENDING,
            'payload_hash' => 'x',
        ]);

        $file = UploadedFile::fake()->createWithContent('attack.png', 'not a real png — just text');

        $this->expectException(\InvalidArgumentException::class);
        (new UploadProcessor)->process($file, $form, $submission, 'attachment');
    }

    public function test_processor_accepts_valid_png_and_records_ulid_key(): void
    {
        Storage::fake('local');
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace, ['allowed_mime_types' => ['image/png']]);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => [],
            'meta' => [],
            'state' => Submission::STATE_PENDING,
            'payload_hash' => 'x',
        ]);

        // Real 1x1 PNG bytes — magic bytes (89 50 4E 47) make finfo agree.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $file = UploadedFile::fake()->createWithContent('photo.png', $pngBytes);

        $record = (new UploadProcessor)->process($file, $form, $submission, 'attachment');

        $this->assertSame('image/png', $record->mime);
        $this->assertSame('photo.png', $record->original_name);
        $this->assertSame(SubmissionFile::SCAN_PENDING, $record->scan_state);
        // Path is uploads/<form>/<submission>/<ulid>.png — ULID-keyed
        $this->assertMatchesRegularExpression('/uploads\/[0-9a-zA-HJKMNP-TV-Z]{26}\/[0-9a-zA-HJKMNP-TV-Z]{26}\/[0-9A-HJKMNP-TV-Z]{26}\.png/', $record->storage_path);
        Storage::disk('local')->assertExists($record->storage_path);
    }

    public function test_scan_job_quarantines_infected_files(): void
    {
        Storage::fake('local');
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => [],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'x',
        ]);
        Storage::disk('local')->put('uploads/X/Y/Z.pdf', 'fake content');
        $file = $submission->files()->create([
            'field_name' => 'attachment',
            'storage_path' => 'uploads/X/Y/Z.pdf',
            'original_name' => 'doc.pdf',
            'mime' => 'application/pdf',
            'size' => 12,
            'scan_state' => SubmissionFile::SCAN_PENDING,
        ]);

        $scanner = new class('127.0.0.1', 3310, 5) extends ClamScanner {
            public function scan(string $bytes): array
            {
                return [ClamScanner::RESULT_INFECTED, 'Eicar-Test-Signature'];
            }
        };

        (new ScanUploadJob($file->id))->handle($scanner);

        $file->refresh();
        $submission->refresh();
        $this->assertSame(SubmissionFile::SCAN_INFECTED, $file->scan_state);
        $this->assertStringStartsWith('quarantine/', $file->storage_path);
        $this->assertSame(Submission::STATE_QUARANTINED, $submission->state);
    }

    public function test_scan_job_marks_clean_when_safe(): void
    {
        Storage::fake('local');
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => [],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'x',
        ]);
        Storage::disk('local')->put('uploads/X/Y/Z.pdf', 'safe content');
        $file = $submission->files()->create([
            'field_name' => 'attachment',
            'storage_path' => 'uploads/X/Y/Z.pdf',
            'original_name' => 'doc.pdf',
            'mime' => 'application/pdf',
            'size' => 12,
            'scan_state' => SubmissionFile::SCAN_PENDING,
        ]);

        $scanner = new class('127.0.0.1', 3310, 5) extends ClamScanner {
            public function scan(string $bytes): array
            {
                return [ClamScanner::RESULT_CLEAN, null];
            }
        };

        (new ScanUploadJob($file->id))->handle($scanner);

        $file->refresh();
        $this->assertSame(SubmissionFile::SCAN_CLEAN, $file->scan_state);
    }
}
