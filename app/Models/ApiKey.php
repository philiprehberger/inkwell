<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'name',
        'prefix',
        'key_hash',
        'last_four',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Mint a new API key, persist hash + metadata, return the plaintext.
     * Caller must capture the plaintext at the moment of creation — it is
     * never retrievable again.
     *
     * Format: whk_{env}_{32 random url-safe chars}
     */
    public static function mint(Workspace $workspace, string $env = 'live', ?string $name = null): array
    {
        if (! in_array($env, ['live', 'test', 'sandbox'], true)) {
            throw new \InvalidArgumentException("env must be 'live', 'test', or 'sandbox'");
        }

        $prefix = "whk_{$env}_";
        $random = Str::random(32);
        $plaintext = $prefix . $random;

        $apiKey = static::create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plaintext),
            'last_four' => substr($plaintext, -4),
        ]);

        return [$apiKey, $plaintext];
    }

    /**
     * Look up an active key by its plaintext value.
     * Constant-time-ish: hashes the candidate and looks up by hash.
     */
    public static function findByPlaintext(?string $plaintext): ?self
    {
        if (! is_string($plaintext) || $plaintext === '') {
            return null;
        }

        $hash = hash('sha256', $plaintext);

        return static::whereNull('revoked_at')
            ->where('key_hash', $hash)
            ->first();
    }

    public function isLive(): Attribute
    {
        return Attribute::get(fn () => $this->prefix === 'whk_live_');
    }

    public function isSandbox(): Attribute
    {
        return Attribute::get(fn () => $this->prefix === 'whk_sandbox_');
    }
}
