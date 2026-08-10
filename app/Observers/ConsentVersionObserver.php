<?php

namespace App\Observers;

use App\Models\Domain;
use App\Models\CookieGroup;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;

/**
 * Observer that auto-increments the consent_version on related Domain(s)
 * whenever consent-relevant configuration changes occur.
 * This forces visitors to re-consent per Borlabs' cookie versioning pattern.
 */
class ConsentVersionObserver
{
    /**
     * Increment consent_version for all domains linked to the modified model.
     */
    protected function incrementVersion($model): void
    {
        $domainIds = collect();

        if ($model instanceof CookieGroup) {
            $domainIds = $model->domains()->pluck('domains.id');
        } elseif ($model instanceof Service) {
            $domainIds = $model->domains()->pluck('domains.id');
        } elseif ($model instanceof Domain) {
            $domainIds = collect([$model->id]);
        }

        if ($domainIds->isNotEmpty()) {
            // Get site_ids BEFORE incrementing (single query instead of two)
            $siteIds = Domain::whereIn('id', $domainIds)->pluck('site_id');
            
            Domain::whereIn('id', $domainIds)->increment('consent_version');

            // Invalidate config cache for affected domains
            foreach ($siteIds as $siteId) {
                // Clear both config and script delivery caches
                Cache::forget("consent_config:{$siteId}");
                // Clear all lang variants of script delivery cache
                $activeLangs = Cache::get('active_language_codes', ['en']);
                foreach ($activeLangs as $lang) {
                    Cache::forget("script_delivery:{$siteId}:{$lang}");
                    Cache::forget("consent_config:{$siteId}:{$lang}");
                }
            }
        }
    }

    public function created($model): void
    {
        $this->incrementVersion($model);
    }

    public function updated($model): void
    {
        $this->incrementVersion($model);
    }

    public function deleted($model): void
    {
        $this->incrementVersion($model);
    }
}
