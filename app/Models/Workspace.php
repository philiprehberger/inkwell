<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug', 'is_sandbox'];

    protected function casts(): array
    {
        return ['is_sandbox' => 'boolean'];
    }

    /**
     * The singleton "Public Sandbox" workspace that anonymous docs-site
     * visitors get keys against. Created on first call.
     */
    public static function sandbox(): self
    {
        return static::firstOrCreate(
            ['slug' => 'public-sandbox'],
            ['name' => 'Public Sandbox', 'is_sandbox' => true],
        );
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function idempotencyRecords(): HasMany
    {
        return $this->hasMany(IdempotencyRecord::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
