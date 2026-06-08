<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IpBlocklistEntry extends Model
{
    use HasUlids;

    protected $fillable = ['workspace_id', 'ip_or_cidr', 'reason', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
