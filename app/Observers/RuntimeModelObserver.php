<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ContentBlocker;
use App\Models\CookieBar;
use App\Models\CookieGroup;
use App\Models\ScriptBlocker;
use App\Models\Service;
use App\Runtime\Publisher\PublishTrigger;

/**
 * RuntimeModelObserver — Triggers manifest recompilation on policy-relevant model changes.
 *
 * This observer covers the gaps in the existing DomainObserver and ConsentVersionObserver:
 *   - CookieBar theme/UI changes
 *   - CookieGroup structure changes
 *   - Service configuration changes
 *   - ScriptBlocker changes
 *   - ContentBlocker changes
 *
 * Registered in AppServiceProvider::boot() for each model.
 */
class RuntimeModelObserver
{
    public function __construct(
        protected PublishTrigger $trigger,
    ) {}

    public function created($model): void
    {
        $this->handleChange($model);
    }

    public function updated($model): void
    {
        $this->handleChange($model);
    }

    public function deleted($model): void
    {
        $this->handleChange($model);
    }

    protected function handleChange($model): void
    {
        match (true) {
            $model instanceof CookieGroup    => $this->trigger->triggerForCookieGroup($model),
            $model instanceof Service        => $this->trigger->triggerForService($model),
            $model instanceof ScriptBlocker  => $this->trigger->triggerForScriptBlocker($model),
            $model instanceof ContentBlocker => $this->trigger->triggerForContentBlocker($model),
            $model instanceof CookieBar      => $this->trigger->triggerForCookieBar($model),
            default => null,
        };
    }
}
