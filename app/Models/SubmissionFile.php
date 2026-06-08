<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionFile extends Model
{
    use HasUlids;

    public const SCAN_PENDING = 'pending';
    public const SCAN_CLEAN = 'clean';
    public const SCAN_INFECTED = 'infected';
    public const SCAN_ERROR = 'error';

    protected $fillable = [
        'submission_id',
        'field_name',
        'storage_path',
        'original_name',
        'mime',
        'size',
        'scan_state',
        'scanned_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'scanned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
