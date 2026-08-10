<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentBlocker extends Model
{
    use \Spatie\Translatable\HasTranslations;

    public $translatable = ['name', 'description'];
    protected $fillable = [
        'name',
        'key',
        'domain_id',
        'service_id',
        'provider_id',
        'group_id',
        'hosts',
        'is_active',
        'is_system',
        'html_code',
        'css_code',
        'js_code',
        'preview_image_url',
        'text_placeholders',
        // Floating / display mode
        'display_mode',
        'floating_position',
        'floating_icon_url',
        'floating_label',
        'design_template',
        // Consent Execution Registry v2
        'provider_key',
        'supports_accept_once',
        'supports_accept_provider',
        'consent_mode_mapping',
    ];

    protected $casts = [
        'hosts' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'text_placeholders' => 'array',
        // Consent Execution Registry v2
        'supports_accept_once' => 'boolean',
        'supports_accept_provider' => 'boolean',
        'consent_mode_mapping' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
