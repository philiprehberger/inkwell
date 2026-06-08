<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use HasUlids, BelongsToWorkspace;

    public const STATE_PENDING = 'pending';
    public const STATE_CLEAN = 'clean';
    public const STATE_SPAM = 'spam';
    public const STATE_QUARANTINED = 'quarantined';
    public const STATE_PROMOTED = 'promoted';
    public const STATE_REJECTED = 'rejected';
    public const STATE_ARCHIVED = 'archived';

    public const ACTIVE_DELIVERY_STATES = [self::STATE_CLEAN, self::STATE_PROMOTED];

    protected $fillable = [
        'form_id',
        'workspace_id',
        'payload',
        'meta',
        'spam_score',
        'spam_signals',
        'state',
        'payload_hash',
        'pii_purged_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'meta' => 'array',
            'spam_signals' => 'array',
            'spam_score' => 'integer',
            'pii_purged_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function isPiiPurged(): bool
    {
        return $this->pii_purged_at !== null;
    }
}
