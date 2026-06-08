<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    use HasUlids, BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'key',
        'body_hash',
        'response',
        'status_code',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'status_code' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
