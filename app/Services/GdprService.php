<?php

namespace App\Services;

use App\Models\Group;
use App\Models\ConsentLog;
use App\Models\User;
use App\Models\ScanResult;
use App\Models\RuntimeRevision;
use App\Models\RuntimeOverlay;
use App\Models\HealthCheckResult;
use App\Models\DailyTrafficReport;
use App\Models\TrafficMetric;
use App\Models\TrafficRumEvent;
use App\Models\GroupInvitation;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GdprService
{
    /**
     * Export all data for a specific group.
     *
     * @param Group $group
     * @return string Path to the exported JSON file.
     */
    public function exportGroup(Group $group): string
    {
        $exportData = [
            'group' => $group->only(['id', 'name', 'slug', 'description', 'created_at']),
            'users' => $group->users()->get()->map(fn($u) => $u->only(['id', 'name', 'email', 'pivot'])),
            'domains' => $group->domains()->get()->map(fn($d) => $d->only(['id', 'name', 'is_active', 'created_at'])),
            'cookie_bars' => $group->cookieBars()->get()->map(fn($cb) => $cb->only(['id', 'name', 'config', 'created_at'])),
            'services' => $group->services()->get()->map(fn($s) => $s->only(['id', 'name', 'type', 'config', 'created_at'])),
            'webhook_endpoints' => $group->webhookEndpoints()->get()->map(fn($we) => $we->only(['id', 'url', 'events', 'is_active', 'created_at'])),
            'consent_logs_summary' => [
                'total_count' => ConsentLog::whereIn('domain_id', $group->domains()->pluck('id'))->count(),
                'note' => 'Full consent logs are excluded from this summary for performance, use Filament Export for raw logs if needed.',
            ],
            'exported_at' => now()->toIso8601String(),
        ];

        $filename = "gdpr-export-group-{$group->id}-" . Str::random(8) . ".json";
        $path = "gdpr-exports/{$filename}";
        
        Storage::disk('local')->put($path, json_encode($exportData, JSON_PRETTY_PRINT));

        return Storage::disk('local')->path($path);
    }

    /**
     * Permanently delete a group and all its associated data.
     *
     * @param Group $group
     * @return void
     */
    public function deleteGroup(Group $group): void
    {
        DB::transaction(function () use ($group) {
            // 1. Get all domain IDs to delete their logs and artifacts
            $domainIds = $group->domains()->pluck('id')->toArray();

            // 2. Cancel active subscriptions (Cashier / Billable on Group)
            foreach ($group->subscriptions as $subscription) {
                if ($subscription instanceof Subscription) {
                    $subscription->cancelNow();
                }
            }

            // 3. Delete Domain-scoped data
            ConsentLog::whereIn('domain_id', $domainIds)->delete();
            ScanResult::whereIn('domain_id', $domainIds)->delete();
            RuntimeRevision::whereIn('domain_id', $domainIds)->delete();
            RuntimeOverlay::whereIn('domain_id', $domainIds)->delete();
            HealthCheckResult::whereIn('domain_id', $domainIds)->delete();
            DailyTrafficReport::whereIn('domain_id', $domainIds)->delete();
            TrafficMetric::whereIn('domain_id', $domainIds)->delete();
            TrafficRumEvent::whereIn('domain_id', $domainIds)->delete();

            // 4. Delete Group-scoped relations and resources
            $group->domains()->delete();
            $group->cookieBars()->delete();
            $group->cookieGroups()->delete();
            $group->services()->delete();
            $group->webhookEndpoints()->delete();
            $group->contentBlockers()->delete();
            $group->scriptBlockers()->delete();
            
            // Delete pending invitations
            GroupInvitation::where('group_id', $group->id)->delete();

            // 5. Handle Users
            foreach ($group->users as $user) {
                if (!$user instanceof User) {
                    continue;
                }
                $group->users()->detach($user->id);

                // Delete user if they have no other groups (orphan account)
                if ($user->groups()->count() === 0) {
                    $user->delete();
                }
            }

            // 6. Delete the group itself
            $group->delete();
        });
    }
}
