<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use \Spatie\Translatable\HasTranslations;

    public $translatable = ['name'];
    protected $fillable = [
        'name',
        'key',
        'group_id',
        'address',
        'privacy_policy_url',
        'cookie_policy_url',
        'opt_out_url',
        'is_library',
    ];

    protected $casts = [
        'is_library' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Provider $provider) {
            if ($provider->is_library) {
                $serviceCount = $provider->services()->count();
                if ($serviceCount > 0) {
                    \Filament\Notifications\Notification::make()
                        ->danger()
                        ->title('Cannot delete library provider')
                        ->body("'{$provider->name}' was installed from the library and still has {$serviceCount} service(s). Delete the library services first — the provider will be removed automatically.")
                        ->persistent()
                        ->send();

                    throw new \Exception("Library provider '{$provider->name}' cannot be deleted while it has services.");
                }
            }
        });
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
