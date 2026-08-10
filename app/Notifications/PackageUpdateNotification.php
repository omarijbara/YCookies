<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\SmtpSetting;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Illuminate\Mail\SentMessage;

class PackageUpdateNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $packageName,
        protected string $packageKey,
        protected string $installedVersion,
        protected string $newVersion,
        protected string $type,
        protected array $changelog = [],
    ) {}

    /**
     * Determine which channels to send on.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $smtp = SmtpSetting::instance();
        if ($smtp->is_active && $smtp->notify_on_updates && $smtp->host) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Database notification (Filament bell icon).
     */
    public function toDatabase(object $notifiable): array
    {
        $typeLabels = [
            'service' => 'Service',
            'script_blocker' => 'Script Blocker',
            'content_blocker' => 'Content Blocker',
            'style_blocker' => 'Style Blocker',
        ];

        $typeLabel = $typeLabels[$this->type] ?? 'Package';

        $notification = \Filament\Notifications\Notification::make()
            ->warning()
            ->icon('heroicon-o-arrow-path')
            ->title("Update available: {$this->packageName}")
            ->body("v{$this->installedVersion} → v{$this->newVersion} ({$typeLabel})")
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('Go to Library')
                    ->url('/admin/1/package-library')
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();

        // Add custom fields for deduplication
        $notification['package_key'] = $this->packageKey;
        $notification['new_version'] = $this->newVersion;

        return $notification;
    }

    /**
     * Email notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $smtp = SmtpSetting::instance();

        $message = (new MailMessage)
            ->subject("📦 Update Available: {$this->packageName} v{$this->newVersion}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new version of **{$this->packageName}** is available in the YCookies Library.")
            ->line("**Current version:** v{$this->installedVersion}")
            ->line("**New version:** v{$this->newVersion}");

        // Add changelog
        if (!empty($this->changelog)) {
            $message->line("**What's new in v{$this->newVersion}:**");
            foreach ($this->changelog as $item) {
                $message->line("• {$item}");
            }
        }

        $message->action('Open Package Library', url('/admin/1/package-library'))
            ->line('Your tracking IDs (GTM ID, GA ID, Pixel ID, etc.) will not be changed during the update.')
            ->salutation('— YCookies Notification System');

        // Set the from address from SMTP settings
        if ($smtp->from_address) {
            $message->from($smtp->from_address, $smtp->from_name ?: 'YCookies');
        }

        return $message;
    }

    /**
     * Unique identifier for deduplication.
     */
    public function databaseUniqueId(): string
    {
        return "package-update-{$this->packageKey}-{$this->newVersion}";
    }
}
