<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CookieGroup extends Model
{
    use \Spatie\Translatable\HasTranslations;

    protected $fillable = [
        'name',
        'key',
        'description',
        'group_id',
        'sort_order',
        'is_required',
        'is_system',
        'is_preselected',
    ];

    public $translatable = ['name', 'description'];

    protected $casts = [
        'is_required' => 'boolean',
        'is_system' => 'boolean',
        'is_preselected' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (CookieGroup $group) {
            if ($group->is_system) {
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title('System group cannot be deleted')
                    ->body("'{$group->name}' is a predefined system group required for cookie bars to function correctly. It cannot be deleted.")
                    ->persistent()
                    ->send();

                throw new \Exception("System group '{$group->name}' cannot be deleted.");
            }

            $serviceCount = $group->services()->count();
            if ($serviceCount > 0) {
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title('Cannot delete this group')
                    ->body("'{$group->name}' still has {$serviceCount} connected service(s). Please delete or move them to another group first.")
                    ->persistent()
                    ->send();

                throw new \Exception("Group '{$group->name}' still has {$serviceCount} connected service(s).");
            }
        });
    }

    /**
     * System groups are predefined and should not be deleted.
     */
    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
