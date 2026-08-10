<?php

namespace App\Services;

use App\Models\ContentBlocker;
use App\Models\ScriptBlocker;
use App\Models\Service;
use App\Models\SmtpSetting;
use App\Models\User;
use App\Notifications\PackageUpdateNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Check all installed packages for available updates and send notifications.
     * Deduplicates so each user only gets notified once per version.
     */
    public static function checkForUpdates(): void
    {
        $templates = TemplateLibraryService::getTemplates();
        $users = User::all();

        // Configure SMTP mailer dynamically if active
        static::configureDynamicMailer();

        // Check services
        $installedServices = Service::whereNotNull('template_key')->get();
        foreach ($installedServices as $service) {
            $tpl = $templates[$service->template_key] ?? null;
            if (!$tpl) continue;

            $libraryVersion = $tpl['version'] ?? '1.0.0';
            $installedVersion = $service->template_version ?? '0.0.0';

            if (version_compare($libraryVersion, $installedVersion, '>')) {
                $changelog = static::getChangelogForVersion($tpl, $installedVersion);
                static::notifyUsers($users, $tpl['name'], $tpl['key'], $installedVersion, $libraryVersion, $tpl['type'], $changelog);
            }
        }

        // Check script blockers (includes style blockers)
        $installedBlockers = ScriptBlocker::whereNotNull('template_key')->get();
        foreach ($installedBlockers as $blocker) {
            $tpl = $templates[$blocker->template_key] ?? null;
            if (!$tpl) continue;

            $libraryVersion = $tpl['version'] ?? '1.0.0';
            $installedVersion = $blocker->template_version ?? '0.0.0';

            if (version_compare($libraryVersion, $installedVersion, '>')) {
                $changelog = static::getChangelogForVersion($tpl, $installedVersion);
                static::notifyUsers($users, $tpl['name'], $tpl['key'], $installedVersion, $libraryVersion, $tpl['type'], $changelog);
            }
        }

        // Check content blockers
        $installedContent = ContentBlocker::whereNotNull('template_key')->get();
        foreach ($installedContent as $cb) {
            $tpl = $templates[$cb->template_key] ?? null;
            if (!$tpl) continue;

            $libraryVersion = $tpl['version'] ?? '1.0.0';
            $installedVersion = $cb->template_version ?? '0.0.0';

            if (version_compare($libraryVersion, $installedVersion, '>')) {
                $changelog = static::getChangelogForVersion($tpl, $installedVersion);
                static::notifyUsers($users, $tpl['name'], $tpl['key'], $installedVersion, $libraryVersion, $tpl['type'], $changelog);
            }
        }
    }

    /**
     * Send notification to all users, skipping those who already received this exact version notification.
     */
    protected static function notifyUsers(
        $users,
        string $packageName,
        string $packageKey,
        string $installedVersion,
        string $newVersion,
        string $type,
        array $changelog
    ): void {
        foreach ($users as $user) {
            // Check if user already has this exact notification (deduplication)
            $alreadyNotified = $user->notifications()
                ->where('type', PackageUpdateNotification::class)
                ->whereJsonContains('data->package_key', $packageKey)
                ->whereJsonContains('data->new_version', $newVersion)
                ->exists();

            if ($alreadyNotified) continue;

            try {
                $user->notify(new PackageUpdateNotification(
                    $packageName,
                    $packageKey,
                    $installedVersion,
                    $newVersion,
                    $type,
                    $changelog,
                ));
            } catch (\Exception $e) {
                Log::warning("Failed to send update notification for {$packageName}: " . $e->getMessage());
            }
        }
    }

    /**
     * Configure Laravel's mailer at runtime from SMTP settings in the database.
     */
    public static function configureDynamicMailer(): void
    {
        $smtp = SmtpSetting::instance();
        if (!$smtp->is_active || !$smtp->host) return;

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $smtp->host);
        Config::set('mail.mailers.smtp.port', $smtp->port);
        Config::set('mail.mailers.smtp.username', $smtp->username);
        Config::set('mail.mailers.smtp.password', $smtp->decrypted_password);
        Config::set('mail.mailers.smtp.encryption', $smtp->encryption);

        if ($smtp->from_address) {
            Config::set('mail.from.address', $smtp->from_address);
            Config::set('mail.from.name', $smtp->from_name ?: 'YCookies');
        }
    }

    /**
     * Get changelog entries newer than the installed version.
     */
    protected static function getChangelogForVersion(array $template, string $installedVersion): array
    {
        if (empty($template['changelog'])) return [];

        $entries = [];
        $latestVersion = $template['version'] ?? '1.0.0';

        // Only get the changelog for the latest version
        if (isset($template['changelog'][$latestVersion])) {
            return $template['changelog'][$latestVersion];
        }

        return $entries;
    }

    /**
     * Mark all update notifications for a specific package as read.
     */
    public static function markPackageUpdateAsRead(string $packageKey): void
    {
        $users = User::all();
        foreach ($users as $user) {
            $user->unreadNotifications()
                ->where('type', PackageUpdateNotification::class)
                ->get()
                ->filter(function ($notification) use ($packageKey) {
                    $data = $notification->data;
                    return ($data['package_key'] ?? '') === $packageKey;
                })
                ->each
                ->markAsRead();
        }
    }
}
