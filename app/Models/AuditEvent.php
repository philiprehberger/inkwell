<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasUlids, BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'actor_type',
        'actor_id',
        'actor_label',
        'subject_type',
        'subject_id',
        'action',
        'diff',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
