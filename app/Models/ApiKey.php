<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUlids, BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'prefix',
        'key_hash',
        'last_four',
        'scopes',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Mint a new key. Returns [model, plaintext]. Plaintext shown once.
     */
    public static function mint(
        Workspace $workspace,
        array $scopes,
        string $env = 'live',
        ?string $name = null,
    ): array {
        if (! in_array($env, ['live', 'test'], true)) {
            throw new \InvalidArgumentException("env must be 'live' or 'test'");
        }
        $prefix = "inkwell_{$env}_";
        $random = Str::random(32);
        $plaintext = $prefix.$random;

        $apiKey = static::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plaintext),
            'last_four' => substr($plaintext, -4),
            'scopes' => $scopes,
        ]);

        return [$apiKey, $plaintext];
    }

    public static function findByPlaintext(?string $plaintext): ?self
    {
        if (! is_string($plaintext) || $plaintext === '') {
            return null;
        }
        return static::withoutGlobalScope(WorkspaceScope::class)
            ->whereNull('revoked_at')
            ->where('key_hash', hash('sha256', $plaintext))
            ->first();
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];
        return in_array('admin', $scopes, true) || in_array($scope, $scopes, true);
    }
}
