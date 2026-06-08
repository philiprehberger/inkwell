<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormDestination extends Model
{
    use HasUlids;

    public const KIND_EMAIL = 'email';
    public const KIND_WEBHOOK = 'webhook';
    public const KIND_SLACK = 'slack';
    public const KIND_DISCORD = 'discord';
    public const KIND_GOOGLE_SHEETS = 'google_sheets';
    public const KIND_HUBSPOT = 'hubspot';
    public const KIND_MAILCHIMP = 'mailchimp';

    public const KINDS = [
        self::KIND_EMAIL,
        self::KIND_WEBHOOK,
        self::KIND_SLACK,
        self::KIND_DISCORD,
        self::KIND_GOOGLE_SHEETS,
        self::KIND_HUBSPOT,
        self::KIND_MAILCHIMP,
    ];

    public const FAST_KINDS = [self::KIND_EMAIL, self::KIND_WEBHOOK, self::KIND_SLACK, self::KIND_DISCORD];

    protected $fillable = [
        'form_id',
        'kind',
        'config',
        'enabled',
        'priority',
        'health',
        'last_attempted_at',
        'last_health_check_at',
        'previous_secret',
        'previous_secret_expires_at',
    ];

    protected $hidden = ['previous_secret'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
            'priority' => 'integer',
            'last_attempted_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'previous_secret_expires_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function isFastQueue(): bool
    {
        return in_array($this->kind, self::FAST_KINDS, true);
    }
}
