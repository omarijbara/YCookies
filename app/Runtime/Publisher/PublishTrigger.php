<?php

declare(strict_types=1);

namespace App\Runtime\Publisher;

use App\Jobs\CompileAndPublishRevision;
use App\Models\ContentBlocker;
use App\Models\CookieBar;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\ScriptBlocker;
use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * PublishTrigger — Dispatches compile jobs when policy-relevant models change.
 *
 * This service determines which domain(s) are affected by a model change
 * and dispatches CompileAndPublishRevision jobs for each.
 *
 * The job itself handles debouncing (uniqueId + uniqueFor) so rapid
 * changes don't cause compile storms.
 */
class PublishTrigger
{
    /**
     * Trigger compile for a domain.
     */
    public function triggerForDomain(Domain $domain): void
    {
        if (!$domain->manifest_enabled) {
            return;
        }

        CompileAndPublishRevision::dispatch($domain->id, auth()->id());
    }

    /**
     * Trigger compile for all domains affected by a CookieGroup change.
     */
    public function triggerForCookieGroup(CookieGroup $group): void
    {
        $this->dispatchForRelatedDomains($group->domains()->pluck('domains.id'));
    }

    /**
     * Trigger compile for all domains affected by a Service change.
     */
    public function triggerForService(Service $service): void
    {
        $this->dispatchForRelatedDomains($service->domains()->pluck('domains.id'));
    }

    /**
     * Trigger compile for all domains affected by a ScriptBlocker change.
     */
    public function triggerForScriptBlocker(ScriptBlocker $blocker): void
    {
        if ($blocker->domain_id) {
            $this->dispatchForDomainId($blocker->domain_id);
        }
    }

    /**
     * Trigger compile for all domains affected by a ContentBlocker change.
     */
    public function triggerForContentBlocker(ContentBlocker $blocker): void
    {
        if ($blocker->domain_id) {
            $this->dispatchForDomainId($blocker->domain_id);
        } elseif ($blocker->group_id) {
            // Trigger compile for all domains in the tenant workspace
            $domainIds = Domain::withoutGlobalScope('tenant')
                ->where('group_id', $blocker->group_id)
                ->where('manifest_enabled', true)
                ->pluck('id');
                
            foreach ($domainIds as $domainId) {
                CompileAndPublishRevision::dispatch($domainId, auth()->id());
            }
        }

        // Also invalidate any domain that uses this blocker as its fallback.
        // When the admin edits the Universal Fallback's HTML/CSS, all domains
        // selecting it via fallback_content_blocker_id must get a fresh config.
        $fallbackDomains = Domain::withoutGlobalScope('tenant')
            ->where('fallback_content_blocker_id', $blocker->id)
            ->get();

        $observer = app(\App\Observers\DomainObserver::class);
        foreach ($fallbackDomains as $domain) {
            $observer->forceBumpConfigVersion($domain);
        }
    }

    /**
     * Trigger compile for all domains using a CookieBar.
     */
    public function triggerForCookieBar(CookieBar $cookieBar): void
    {
        $domainIds = Domain::withoutGlobalScope('tenant')
            ->where('cookie_bar_id', $cookieBar->id)
            ->where('manifest_enabled', true)
            ->pluck('id');

        foreach ($domainIds as $domainId) {
            CompileAndPublishRevision::dispatch($domainId, auth()->id());
        }
    }

    /**
     * Dispatch compile jobs for a collection of domain IDs.
     */
    protected function dispatchForRelatedDomains($domainIds): void
    {
        $enabledDomainIds = Domain::withoutGlobalScope('tenant')
            ->whereIn('id', $domainIds)
            ->where('manifest_enabled', true)
            ->pluck('id');

        foreach ($enabledDomainIds as $domainId) {
            CompileAndPublishRevision::dispatch($domainId, auth()->id());
        }
    }

    /**
     * Dispatch compile job for a single domain ID.
     */
    protected function dispatchForDomainId(int $domainId): void
    {
        $enabled = Domain::withoutGlobalScope('tenant')
            ->where('id', $domainId)
            ->where('manifest_enabled', true)
            ->exists();

        if ($enabled) {
            CompileAndPublishRevision::dispatch($domainId, auth()->id());
        }
    }
}
