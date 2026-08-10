<?php

namespace App\Notifications;

use App\Models\DailyTrafficReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * DailyTrafficDigestNotification — email notification for daily traffic digest.
 *
 * Sent once per group per day (guarded by notified_at on the group summary row).
 */
class DailyTrafficDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected DailyTrafficReport $report,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $report = $this->report;
        $date = $report->report_date->toDateString();
        $group = $report->group;
        $status = strtoupper($report->summary_status);

        $statusEmoji = match ($report->summary_status) {
            'stable'   => '✅',
            'degraded' => '⚠️',
            'critical' => '🔴',
            default    => 'ℹ️',
        };

        $mail = (new MailMessage)
            ->subject("{$statusEmoji} YCookies Daily Digest — {$date}")
            ->greeting("Daily Traffic Digest for {$group->name}")
            ->line("**Date:** {$date}")
            ->line("**Status:** {$statusEmoji} {$status}");

        // Core KPIs
        if ($report->total_requests > 0) {
            $mail->line("---")
                ->line("**Total Requests:** " . number_format($report->total_requests));

            if ($report->edge_p95_latency_ms !== null) {
                $mail->line("**p95 Latency:** {$report->edge_p95_latency_ms}ms");
            }

            if ($report->inject_rate !== null) {
                $mail->line("**Script Injection Rate:** {$report->inject_rate}%");
            }

            if ($report->banner_render_rate !== null) {
                $mail->line("**Banner Render Rate:** {$report->banner_render_rate}%");
            }

            if ($report->alert_count > 0) {
                $mail->line("**Active Alerts:** {$report->alert_count}");
            }
        }

        // Trend arrows
        $trends = $report->trend_json;
        if (!empty($trends['vs_prev_day'])) {
            $mail->line("---");
            $prev = $trends['vs_prev_day'];

            if (isset($prev['p95_delta_pct'])) {
                $arrow = $prev['p95_delta_pct'] > 0 ? '📈' : '📉';
                $mail->line("{$arrow} Latency: " . sprintf('%+.1f%%', $prev['p95_delta_pct']) . " vs yesterday");
            }

            if (isset($prev['request_delta_pct'])) {
                $arrow = $prev['request_delta_pct'] > 0 ? '📈' : '📉';
                $mail->line("{$arrow} Traffic: " . sprintf('%+.1f%%', $prev['request_delta_pct']) . " vs yesterday");
            }
        }

        // Recommendations
        $recs = $report->recommendations_json;
        if (!empty($recs)) {
            $mail->line("---");
            $mail->line("**Recommendations:**");
            foreach ($recs as $rec) {
                $mail->line("• {$rec}");
            }
        }

        // AI brief (additive)
        if ($report->ai_brief) {
            $mail->line("---");
            $mail->line("**AI Analysis:**");
            $mail->line($report->ai_brief);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'daily_traffic_digest',
            'report_id'      => $this->report->id,
            'report_date'    => $this->report->report_date->toDateString(),
            'summary_status' => $this->report->summary_status,
            'total_requests' => $this->report->total_requests,
            'alert_count'    => $this->report->alert_count,
        ];
    }
}
