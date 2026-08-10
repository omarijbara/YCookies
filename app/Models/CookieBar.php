<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class CookieBar extends Model
{
    protected $fillable = [
        'name',
        'group_id',
        'theme_settings',
        'translations',
        'ui_config',
    ];

    protected $casts = [
        'theme_settings' => 'array',
        'ui_config' => 'array',
    ];

    /**
     * Ensure translations always has the full nested structure so
     * Filament's KeyValue fields never receive null on nested paths.
     */
    protected function translations(): Attribute
    {
        $defaults = [
            'banner' => [
                'title' => [
                    'en' => 'Cookie Settings',
                    'de' => 'Cookie-Einstellungen',
                    'ar' => 'إعدادات ملفات تعريف الارتباط',
                    'es' => 'Configuración de cookies',
                ],
                'description' => [
                    'en' => 'We use cookies and similar technologies to ensure the proper functioning of our website, analyze our traffic, and personalize your experience. Please select your preferences below.',
                    'de' => 'Wir verwenden Cookies und ähnliche Technologien, um das ordnungsgemäße Funktionieren unserer Website sicherzustellen, unsere Zugriffe zu analysieren und Ihre Erfahrung zu personalisieren. Bitte wählen Sie unten Ihre Präferenzen aus.',
                    'ar' => 'نحن نستخدم ملفات تعريف الارتباط وتقنيات مشابهة لضمان العمل السليم لموقعنا، وتحليل حركة المرور، وتخصيص تجربتك. يرجى تحديد تفضيلاتك أدناه.',
                    'es' => 'Utilizamos cookies y tecnologías similares para garantizar el correcto funcionamiento de nuestro sitio web, analizar nuestro tráfico y personalizar su experiencia. Por favor, seleccione sus preferencias a continuación.',
                ],
                'declaration_text' => [
                    'en' => 'Cookie Declaration',
                    'de' => 'Cookie-Erklärung',
                    'ar' => 'بيان ملفات تعريف الارتباط',
                    'es' => 'Declaración de cookies',
                ],
                'cross_domain_text' => [
                    'en' => 'Your consent applies to the following domains:',
                    'de' => 'Ihre Einwilligung gilt für folgende Domains:',
                    'ar' => 'تنطبق موافقتك على النطاقات التالية:',
                    'es' => 'Su consentimiento se aplica a los siguientes dominios:',
                ],
                'accept_all_btn' => [
                    'en' => 'Accept All',
                    'de' => 'Alle akzeptieren',
                    'ar' => 'قبول الكل',
                    'es' => 'Aceptar todo',
                ],
                'individual_settings_btn' => [
                    'en' => 'Manage Preferences',
                    'de' => 'Einstellungen verwalten',
                    'ar' => 'إدارة التفضيلات',
                    'es' => 'Gestionar preferencias',
                ],
                'save_btn' => [
                    'en' => 'Save',
                    'de' => 'Speichern',
                    'ar' => 'حفظ',
                    'es' => 'Guardar',
                ],
                'save_consent_btn' => [
                    'en' => 'Save Consent',
                    'de' => 'Einwilligung speichern',
                    'ar' => 'حفظ الموافقة',
                    'es' => 'Guardar consentimiento',
                ],
                'accept_essential_only_btn' => [
                    'en' => 'Essential Only',
                    'de' => 'Nur essentielle',
                    'ar' => 'الأساسية فقط',
                    'es' => 'Solo esenciales',
                ],
            ],
            'links' => [
                'imprint_text' => [
                    'en' => 'Imprint',
                    'de' => 'Impressum',
                    'ar' => 'البصمة القانونية',
                    'es' => 'Aviso legal',
                ],
                'imprint_url' => [
                    'en' => '/imprint',
                    'de' => '/impressum',
                    'ar' => '/imprint',
                    'es' => '/aviso-legal',
                ],
                'privacy_text' => [
                    'en' => 'Privacy Policy',
                    'de' => 'Datenschutzerklärung',
                    'ar' => 'سياسة الخصوصية',
                    'es' => 'Política de privacidad',
                ],
                'privacy_url' => [
                    'en' => '/privacy',
                    'de' => '/datenschutz',
                    'ar' => '/privacy',
                    'es' => '/politica-de-privacidad',
                ],
            ],
        ];

        return Attribute::make(
            get: function ($value) use ($defaults) {
                $data = is_string($value) ? json_decode($value, true) : $value;
                if (!is_array($data)) {
                    return $defaults;
                }
                // Deep merge: defaults first, then overlay with non-empty saved data
                return $this->deepMergeWithDefaults($defaults, $data);
            },
            set: function ($value) {
                return is_string($value) ? $value : json_encode($value);
            },
        );
    }

    /**
     * Deep merge defaults with saved data.
     * - If saved value is a non-empty string/scalar, keep it (user customized).
     * - If saved value is an empty array or null, use the default.
     * - If both are arrays, recurse.
     * - If default is an array but saved is a scalar (legacy format), use default.
     */
    private function deepMergeWithDefaults(array $defaults, array $data): array
    {
        $result = $defaults;

        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $result)) {
                // Extra key from saved data, keep it
                $result[$key] = $value;
            } elseif (is_array($result[$key]) && is_array($value)) {
                if (empty($value)) {
                    // Saved data has empty array — keep default
                    continue;
                }
                $result[$key] = $this->deepMergeWithDefaults($result[$key], $value);
            } elseif (is_array($result[$key]) && !is_array($value)) {
                // Default expects array (per-language) but saved is scalar (legacy format)
                // Keep the default array structure
                continue;
            } elseif ($value !== null && $value !== '') {
                // Non-empty scalar from saved data — use it
                $result[$key] = $value;
            }
            // Else: null or empty string from saved data — keep default
        }

        return $result;
    }

    public function domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
