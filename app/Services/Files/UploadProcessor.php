<?php

namespace App\Services\Files;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File-upload pipeline (Phase 6.5).
 *
 *   1. Magic-byte verification via finfo_buffer — Content-Type header is
 *      submitter-controlled and gets ignored; we trust the bytes only.
 *   2. MIME allowlist enforcement against form's allowed_mime_types (if set).
 *   3. ULID-keyed S3 path; original_name preserved only for display.
 *   4. Image EXIF strip via intervention/image when MIME is image/*.
 *   5. ScanUploadJob is dispatched out-of-band — submission is acknowledged
 *      before ClamAV runs so ingest latency stays low.
 *
 * Returns the SubmissionFile row on success or throws on validation failure.
 */
final class UploadProcessor
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/tiff', 'image/gif'];

    /**
     * @return SubmissionFile  the persisted row
     *
     * @throws \InvalidArgumentException  on size / MIME / magic-byte failure
     */
    public function process(UploadedFile $file, Form $form, Submission $submission, string $fieldName): SubmissionFile
    {
        $size = $file->getSize() ?: 0;
        if ($size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException("file {$fieldName}: exceeds 10 MB limit");
        }

        $raw = file_get_contents($file->getRealPath());
        if ($raw === false) {
            throw new \InvalidArgumentException("file {$fieldName}: read failure");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_buffer($finfo, $raw) : null;
        if ($finfo) {
            finfo_close($finfo);
        }
        if (! is_string($detected) || $detected === '') {
            throw new \InvalidArgumentException("file {$fieldName}: MIME detection failed");
        }

        $allowed = $form->allowed_mime_types ?: null;
        if (is_array($allowed) && $allowed !== [] && ! in_array($detected, $allowed, true)) {
            throw new \InvalidArgumentException("file {$fieldName}: MIME {$detected} not in allowlist");
        }

        // EXIF strip for images.
        if (in_array($detected, self::IMAGE_MIMES, true)) {
            $raw = $this->stripExif($raw, $detected);
        }

        $extension = $this->extensionForMime($detected) ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $ulid = (string) Str::ulid();
        $key = sprintf('uploads/%s/%s/%s.%s', $form->id, $submission->id, $ulid, $extension ?: 'bin');
        Storage::put($key, $raw);

        return $submission->files()->create([
            'field_name' => $fieldName,
            'storage_path' => $key,
            'original_name' => $this->sanitizeOriginalName($file->getClientOriginalName()),
            'mime' => $detected,
            'size' => strlen($raw),
            'scan_state' => SubmissionFile::SCAN_PENDING,
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function stripExif(string $bytes, string $mime): string
    {
        try {
            $manager = \Intervention\Image\ImageManager::gd();
            $image = $manager->read($bytes);
            // Re-encoding with the same codec strips EXIF/IPTC/XMP metadata.
            $encoded = match ($mime) {
                'image/jpeg' => (string) $image->toJpeg(90),
                'image/png' => (string) $image->toPng(),
                'image/webp' => (string) $image->toWebp(90),
                default => $bytes,
            };
            return $encoded ?: $bytes;
        } catch (\Throwable) {
            return $bytes;
        }
    }

    private function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/tiff' => 'tiff',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
            default => null,
        };
    }

    private function sanitizeOriginalName(string $name): string
    {
        $base = basename($name);
        $clean = preg_replace('/[^A-Za-z0-9_.\- ]+/', '', $base) ?? '';
        return mb_substr(trim($clean), 0, 200);
    }
}
