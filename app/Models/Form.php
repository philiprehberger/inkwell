<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Form extends Model
{
    use HasUlids, BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'slug',
        'name',
        'schema',
        'spam_threshold',
        'success_redirect_url',
        'cors_origins',
        'accept_any_origin',
        'accept_any_origin_set_at',
        'allowed_mime_types',
        'honeypot_field',
        'from_email',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'cors_origins' => 'array',
            'allowed_mime_types' => 'array',
            'accept_any_origin' => 'boolean',
            'accept_any_origin_set_at' => 'datetime',
            'archived_at' => 'datetime',
            'spam_threshold' => 'integer',
        ];
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(FormDestination::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
