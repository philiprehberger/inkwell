<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasUlids;

    public const STATE_PENDING = 'pending';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    public const STATE_DEAD = 'dead';

    protected $fillable = [
        'submission_id',
        'destination_id',
        'state',
        'attempts',
        'replay_sequence',
        'final_status_code',
        'last_attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'replay_sequence' => 'integer',
            'final_status_code' => 'integer',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(FormDestination::class, 'destination_id');
    }

    public function attemptRecords(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
