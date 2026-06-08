<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DataSubjectRequest extends Model
{
    use HasUlids, BelongsToWorkspace;

    public const STATE_QUEUED = 'queued';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'workspace_id',
        'email_hash',
        'reason',
        'state',
        'submissions_purged',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'submissions_purged' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
