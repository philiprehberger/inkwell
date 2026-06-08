<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAttempt extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'attempt_number',
        'request_summary',
        'response_status',
        'response_body_snippet',
        'latency_ms',
        'error_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'response_status' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
