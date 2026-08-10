<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptBlocker extends Model
{
    use \Spatie\Translatable\HasTranslations;

    public const TYPE_SCRIPT = 'script';
    public const TYPE_STYLE = 'style';

    public $translatable = ['name'];
    protected $fillable = [
        'name',
        'key',
        'domain_id',
        'service_id',
        'group_id',
        'handles',
        'phrases',
        'hosts',
        'require_group',
        'on_exist',
        'is_active',
        'is_system',
        'blocker_type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'handles' => 'array',
        'phrases' => 'array',
        'hosts' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ScriptBlocker $blocker): void {
            if (!$blocker->blocker_type) {
                $blocker->blocker_type = self::TYPE_SCRIPT;
            }
        });

        static::deleting(function (ScriptBlocker $blocker): void {
            if ($blocker->is_system) {
                throw new \RuntimeException('System blockers cannot be deleted.');
            }
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
