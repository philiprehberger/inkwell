<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug'];

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
