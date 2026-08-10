<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Server Infrastructure Service
 *
 * Reads real-time system metrics from within the container:
 * - Disk: `df` command
 * - Memory: `/proc/meminfo`
 * - CPU: `/proc/loadavg`, `/proc/cpuinfo`
 * - Uptime: `/proc/uptime`
 *
 * Docker stats come from the Coolify API (if COOLIFY_API_TOKEN is set).
 */
class ServerInfraService
{
    // ── System Metrics (from /proc) ──────────────────────────

    /**
     * Get disk usage for the root filesystem.
     */
    public function getDiskUsage(): array
    {
        try {
            $output = @shell_exec('df -B1 / 2>/dev/null | tail -1');
            if (! $output) {
                return $this->emptyDisk();
            }

            $parts = preg_split('/\s+/', trim($output));
            if (count($parts) < 6) {
                return $this->emptyDisk();
            }

            $totalBytes = (int) $parts[1];
            $usedBytes  = (int) $parts[2];
            $freeBytes  = (int) $parts[3];

            return [
                'total_bytes'   => $totalBytes,
                'used_bytes'    => $usedBytes,
                'free_bytes'    => $freeBytes,
                'total_gb'      => round($totalBytes / 1073741824, 1),
                'used_gb'       => round($usedBytes / 1073741824, 1),
                'free_gb'       => round($freeBytes / 1073741824, 1),
                'percent'       => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0,
                'status'        => $this->diskStatus($totalBytes > 0 ? ($usedBytes / $totalBytes) * 100 : 0),
            ];
        } catch (\Throwable $e) {
            Log::debug('[server-infra] Disk check failed: ' . $e->getMessage());
            return $this->emptyDisk();
        }
    }

    /**
     * Get memory usage from /proc/meminfo.
     */
    public function getMemoryUsage(): array
    {
        try {
            $meminfo = @file_get_contents('/proc/meminfo');
            if (! $meminfo) {
                return $this->emptyMemory();
            }

            $values = [];
            foreach (explode("\n", $meminfo) as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $m)) {
                    $values[$m[1]] = (int) $m[2];
                }
            }

            $totalKb     = $values['MemTotal'] ?? 0;
            $freeKb      = $values['MemFree'] ?? 0;
            $availableKb = $values['MemAvailable'] ?? $freeKb;
            $buffersKb   = $values['Buffers'] ?? 0;
            $cachedKb    = $values['Cached'] ?? 0;
            $usedKb      = $totalKb - $availableKb;

            return [
                'total_mb'     => round($totalKb / 1024),
                'used_mb'      => round($usedKb / 1024),
                'free_mb'      => round($freeKb / 1024),
                'available_mb' => round($availableKb / 1024),
                'buffers_mb'   => round($buffersKb / 1024),
                'cached_mb'    => round($cachedKb / 1024),
                'percent'      => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : 0,
                'status'       => $this->memoryStatus($totalKb > 0 ? ($usedKb / $totalKb) * 100 : 0),
            ];
        } catch (\Throwable $e) {
            Log::debug('[server-infra] Memory check failed: ' . $e->getMessage());
            return $this->emptyMemory();
        }
    }

    /**
     * Get CPU load averages and core count.
     */
    public function getCpuLoad(): array
    {
        try {
            $loadavg = @file_get_contents('/proc/loadavg');
            $cpuinfo = @file_get_contents('/proc/cpuinfo');

            $cores = 0;
            if ($cpuinfo) {
                $cores = substr_count($cpuinfo, 'processor');
            }

            $load1 = $load5 = $load15 = 0;
            if ($loadavg && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $loadavg, $m)) {
                $load1  = (float) $m[1];
                $load5  = (float) $m[2];
                $load15 = (float) $m[3];
            }

            $loadPercent = $cores > 0 ? round(($load1 / $cores) * 100, 1) : 0;

            return [
                'load_1m'      => $load1,
                'load_5m'      => $load5,
                'load_15m'     => $load15,
                'cores'        => $cores,
                'load_percent' => min($loadPercent, 100),
                'status'       => $this->cpuStatus($loadPercent),
            ];
        } catch (\Throwable $e) {
            Log::debug('[server-infra] CPU check failed: ' . $e->getMessage());
            return ['load_1m' => 0, 'load_5m' => 0, 'load_15m' => 0, 'cores' => 0, 'load_percent' => 0, 'status' => 'unknown'];
        }
    }

    /**
     * Get server uptime.
     */
    public function getUptime(): array
    {
        try {
            $uptime = @file_get_contents('/proc/uptime');
            if (! $uptime) {
                return ['days' => 0, 'hours' => 0, 'minutes' => 0, 'formatted' => '—'];
            }

            $seconds = (int) explode(' ', trim($uptime))[0];
            $days    = floor($seconds / 86400);
            $hours   = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return [
                'days'      => $days,
                'hours'     => $hours,
                'minutes'   => $minutes,
                'seconds'   => $seconds,
                'formatted' => $days > 0
                    ? "{$days}d {$hours}h {$minutes}m"
                    : "{$hours}h {$minutes}m",
            ];
        } catch (\Throwable $e) {
            return ['days' => 0, 'hours' => 0, 'minutes' => 0, 'formatted' => '—'];
        }
    }

    // ── Coolify API (Docker + cleanup) ──────────────────────

    /**
     * Get all data from Coolify API: server info, resources, cleanup settings.
     * Cached for 60 seconds to avoid hammering the API.
     */
    public function getCoolifyData(): ?array
    {
        if (! $this->hasCoolifyCredentials()) {
            return null;
        }

        return Cache::remember('server_infra_coolify', 60, function () {
            try {
                $serverUuid = $this->getServerUuid();
                if (!$serverUuid) return null;

                $server    = $this->coolifyGet("/api/v1/servers/{$serverUuid}");
                $resources = $this->coolifyGet("/api/v1/servers/{$serverUuid}/resources");

                return [
                    'server'    => $server,
                    'settings'  => $server['settings'] ?? [],
                    'resources' => $resources ?? [],
                ];
            } catch (\Throwable $e) {
                Log::debug('[server-infra] Coolify API failed: ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Get cleanup settings from Coolify.
     */
    public function getCleanupSettings(): array
    {
        $data = $this->getCoolifyData();
        $settings = $data['settings'] ?? [];

        return [
            'frequency'                => $settings['docker_cleanup_frequency'] ?? '—',
            'threshold'                => $settings['docker_cleanup_threshold'] ?? 80,
            'force_cleanup'            => $settings['force_docker_cleanup'] ?? false,
            'delete_unused_volumes'    => $settings['delete_unused_volumes'] ?? false,
            'delete_unused_networks'   => $settings['delete_unused_networks'] ?? false,
            'disable_image_retention'  => $settings['disable_application_image_retention'] ?? false,
            'disk_check_frequency'     => $settings['server_disk_usage_check_frequency'] ?? '—',
            'disk_alert_threshold'     => $settings['server_disk_usage_notification_threshold'] ?? 80,
        ];
    }

    /**
     * Get Coolify applications with their status.
     */
    public function getApplications(): array
    {
        $data = $this->getCoolifyData();
        $resources = $data['resources'] ?? [];

        return collect($resources)->map(fn ($r) => [
            'uuid'   => $r['uuid'] ?? '',
            'name'   => $r['name'] ?? 'Unknown',
            'type'   => $r['type'] ?? 'unknown',
            'status' => $r['status'] ?? 'unknown',
        ])->toArray();
    }

    /**
     * Trigger manual Docker cleanup (safe — no volumes, dangling only).
     */
    public function triggerCleanup(): array
    {
        return $this->executeServerCommand(
            'docker system prune -f 2>&1 | tail -5',
            'Docker system prune (safe — no volumes)',
            50
        );
    }

    // ── Server-Level Cleanup (Docker socket → SSH → Coolify API) ─

    /**
     * Execute a command on the Docker host.
     *
     * Priority order:
     * 1. Direct shell_exec — works when Docker socket is mounted (fastest)
     * 2. SSH to Docker host — works when Coolify SSH keys are mounted
     * 3. Coolify API /command — unreliable in Coolify v4
     */
    public function executeServerCommand(string $command, string $label = 'Command', int $timeout = 30): array
    {
        // Validate command against whitelist
        if (!$this->isAllowedCommand($command)) {
            return ['success' => false, 'message' => "Command not in whitelist: {$command}"];
        }

        // 1. Try direct execution (Docker socket mounted at /var/run/docker.sock)
        $directResult = $this->executeDirectly($command, $label);
        if ($directResult !== null) {
            return $directResult;
        }

        // 2. Try SSH to Docker host (key from database)
        $sshResult = $this->executeViaSsh($command, $label, $timeout);
        if ($sshResult !== null) {
            return $sshResult;
        }

        // No execution method available
        $settings = \App\Models\CoolifySetting::instance();
        if (!$settings->isSshConfigured()) {
            return ['success' => false, 'message' => 'SSH not configured. Set up SSH access in Settings → Infrastructure.'];
        }

        return ['success' => false, 'message' => 'Could not connect to host server. Check SSH settings.'];
    }

    /**
     * Execute a command directly via shell_exec.
     * Works when the Docker socket is bind-mounted into the container.
     * Returns null if Docker CLI is not available.
     */
    protected function executeDirectly(string $command, string $label): ?array
    {
        // Direct execution only works when the Docker socket is available
        if (!file_exists('/var/run/docker.sock')) {
            return null;
        }

        // Check if docker CLI is available
        $dockerPath = trim(@shell_exec('which docker 2>/dev/null') ?? '');
        if (empty($dockerPath)) {
            return null;
        }

        try {
            $output = @shell_exec($command . ' 2>&1');

            if ($output === null) {
                return ['success' => false, 'message' => "{$label} returned no output"];
            }

            $output = trim($output);

            // Detect Docker connection failures (socket exists but daemon not running)
            if (str_contains($output, 'failed to connect') || str_contains($output, 'Cannot connect')) {
                Log::debug("[server-infra] Direct docker failed (no daemon): {$output}");
                return null;  // Fall through to SSH
            }

            Cache::forget('server_infra_coolify');

            if (strlen($output) > 300) {
                $output = substr($output, -300);
            }

            return ['success' => true, 'message' => "{$label} completed.\n{$output}"];
        } catch (\Throwable $e) {
            Log::debug("[server-infra] Direct execution failed for {$label}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Execute a command on the host via SSH.
     *
     * SSH configuration is read from the database (CoolifySetting).
     * The private key is decrypted from DB and written to a temp file.
     *
     * Returns null if SSH is not configured.
     */
    protected function executeViaSsh(string $command, string $label, int $timeout): ?array
    {
        $keyFile = $this->getSshKeyFile();
        if (!$keyFile) {
            return null;
        }

        $settings = \App\Models\CoolifySetting::instance();
        $host = $settings->ssh_host;
        $port = $settings->ssh_port ?? 22;
        $user = $settings->ssh_user ?? 'root';

        if (empty($host)) {
            Log::debug("[server-infra] No SSH host configured for: {$label}");
            return ['success' => false, 'message' => 'No SSH host configured.'];
        }

        $sshCommand = sprintf(
            'ssh -q -i %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5 -o BatchMode=yes -p %s %s@%s %s 2>&1',
            escapeshellarg($keyFile),
            escapeshellarg((string) $port),
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($command)
        );

        try {
            Log::debug("[server-infra] SSH executing {$label}: {$command} (timeout: {$timeout}s)");

            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($sshCommand, $descriptorspec, $pipes);

            if (!is_resource($process)) {
                Log::warning("[server-infra] Failed to open SSH process for {$label}");
                return ['success' => false, 'message' => 'Failed to open SSH process.'];
            }

            fclose($pipes[0]);

            stream_set_timeout($pipes[1], $timeout);
            stream_set_timeout($pipes[2], $timeout);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            Log::debug("[server-infra] SSH {$label} exit={$exitCode}, stdout=" . strlen($stdout) . "B, stderr=" . strlen($stderr) . "B");

            Cache::forget('server_infra_coolify');

            if ($exitCode === 0) {
                $output = trim($stdout);
                if (strlen($output) > 300) {
                    $output = substr($output, -300);
                }
                return ['success' => true, 'message' => "{$label} completed.\n{$output}"];
            }

            $errorOutput = trim($stderr ?: $stdout);
            Log::warning("[server-infra] SSH {$label} failed (exit {$exitCode}): {$errorOutput}");
            return ['success' => false, 'message' => "{$label} failed (exit {$exitCode}): {$errorOutput}"];
        } catch (\Throwable $e) {
            Log::error("[server-infra] SSH execution exception for {$label}: " . $e->getMessage());
            return ['success' => false, 'message' => 'SSH execution failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get (or create) the SSH key file path.
     * Priority:
     * 1. Existing valid key at /tmp/.ssh_server_cleanup_key
     * 2. Key from database (CoolifySetting) — written to tmp file
     */
    protected function getSshKeyFile(): ?string
    {
        $tmpKey = '/tmp/ssh_server_key_' . getmyuid() . '.pem';

        // Check if key file already exists and is valid
        if (is_file($tmpKey) && is_readable($tmpKey)) {
            $content = @file_get_contents($tmpKey);
            if ($content && str_contains($content, 'PRIVATE KEY')) {
                return $tmpKey;
            }
        }

        // Load from database
        $settings = \App\Models\CoolifySetting::instance();
        $privateKey = $settings->decrypted_ssh_private_key;

        if (!empty($privateKey) && str_contains($privateKey, 'PRIVATE KEY')) {
            file_put_contents($tmpKey, $privateKey);
            chmod($tmpKey, 0600);
            if (is_file($tmpKey) && is_readable($tmpKey)) {
                return $tmpKey;
            }
        }

        return null;
    }

    /**
     * Check if a command is in the allowed whitelist.
     */
    protected function isAllowedCommand(string $command): bool
    {
        $allowedPrefixes = [
            'docker system df',
            'docker ps -a',
            'docker image prune',
            'docker container prune',
            'docker volume prune',
            'docker builder prune',
            'docker system prune',
            'nohup docker builder prune',
            'nohup docker system prune',
            'journalctl --vacuum-time',
            'find /tmp -type f -atime',
            'apt-get clean',
            'apk cache clean',
        ];

        $cleanCmd = trim(preg_replace('/\s+2>[>&]?1$/', '', $command));

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($cleanCmd, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of stopped/exited Docker services on the server.
     * Used to warn users before pruning images.
     */
    public function getStoppedServices(): array
    {
        $result = $this->executeServerCommand(
            'docker ps -a --filter "status=exited" --filter "status=dead" --format "{{.Names}}|{{.Status}}" 2>&1',
            'List stopped services',
            10
        );

        if (!$result['success'] || empty(trim($result['message']))) {
            return [];
        }

        $services = [];
        foreach (explode("\n", trim($result['message'])) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'Warning:')) {
                continue;
            }
            $parts = explode('|', $line, 2);
            if (count($parts) === 2) {
                $services[] = [
                    'name'   => $parts[0],
                    'status' => $parts[1],
                ];
            }
        }

        return $services;
    }

    /**
     * Prune unused Docker images older than the given age.
     */
    public function pruneDockerImages(string $age = '24h'): array
    {
        // Whitelist allowed age values to prevent injection
        $allowed = ['1h', '2h', '4h', '6h', '12h', '24h', '48h', '72h'];
        if (!in_array($age, $allowed)) {
            $age = '24h';
        }

        return $this->executeServerCommand(
            "docker image prune -af --filter \"until={$age}\" 2>&1 | tail -3",
            "Docker image prune (unused >{$age})",
            45
        );
    }

    /**
     * Prune stopped containers.
     */
    public function pruneDockerContainers(): array
    {
        return $this->executeServerCommand(
            'docker container prune -f --filter "until=24h" 2>&1 | tail -3',
            'Docker container prune (stopped >24h)',
            30
        );
    }

    /**
     * Prune unused volumes.
     */
    public function pruneDockerVolumes(): array
    {
        return $this->executeServerCommand(
            'docker volume prune -f 2>&1 | tail -3',
            'Docker volume prune (anonymous only)',
            30
        );
    }

    /**
     * Prune Docker build cache.
     */
    public function pruneDockerBuildCache(): array
    {
        return $this->executeServerCommand(
            'nohup docker builder prune -af > /dev/null 2>&1 & echo "Build cache prune started in background — results on next refresh"',
            'Docker build cache prune',
            10
        );
    }

    /**
     * Full Docker system prune (images + containers + volumes + build cache).
     */
    public function pruneDockerAll(): array
    {
        return $this->executeServerCommand(
            'docker system prune -f 2>&1 | tail -5',
            'Docker system prune (safe — no volumes)',
            50
        );
    }

    /**
     * Get Docker disk usage summary.
     */
    public function getDockerDiskUsage(): array
    {
        $result = $this->executeServerCommand(
            'docker system df 2>&1',
            'Docker disk usage',
            15
        );

        if (! $result['success']) {
            return ['success' => false, 'images' => '—', 'containers' => '—', 'volumes' => '—', 'build_cache' => '—', 'total' => '—'];
        }

        $output = $result['message'] ?? '';
        $parsed = $this->parseDockerDf($output);
        $parsed['success'] = true;

        return $parsed;
    }

    /**
     * Vacuum systemd journal logs older than 3 days.
     */
    public function vacuumJournalLogs(): array
    {
        return $this->executeServerCommand(
            'journalctl --vacuum-time=3d 2>&1 | tail -3',
            'Journal log vacuum'
        );
    }

    /**
     * Clean system temp files and package cache.
     */
    public function cleanSystemTemp(): array
    {
        $cmd = implode(' && ', [
            'find /tmp -type f -atime +7 -delete 2>/dev/null; echo "Cleaned /tmp"',
            'apt-get clean 2>/dev/null || apk cache clean 2>/dev/null || true',
            'echo "Package cache cleaned"',
        ]);

        return $this->executeServerCommand($cmd, 'System temp cleanup');
    }

    /**
     * Parse `docker system df` output into structured data.
     */
    protected function parseDockerDf(string $output): array
    {
        $data = [
            'images'      => '—',
            'containers'  => '—',
            'volumes'     => '—',
            'build_cache' => '—',
            'total'       => '—',
            'raw'         => $output,
        ];

        $totalBytes = 0;
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Match lines like: "Images    5    2    1.2GB    500MB"
            if (preg_match('/^(Images|Containers|Local Volumes|Build Cache)\s+(\d+)\s+(\d+)\s+([\d.]+[KMGBT]?B?)\s+([\d.]+[KMGBT]?B?)/i', trim($line), $m)) {
                $type       = strtolower(trim($m[1]));
                $count      = (int) $m[2];
                $active     = (int) $m[3];
                $size       = $m[4];
                $reclaimable = $m[5];

                $key = match(true) {
                    str_contains($type, 'image')  => 'images',
                    str_contains($type, 'container') => 'containers',
                    str_contains($type, 'volume') => 'volumes',
                    str_contains($type, 'build')  => 'build_cache',
                    default => null,
                };

                if ($key) {
                    if ($key === 'images' && $count === $active && $reclaimable !== '0B') {
                        $data['images'] = "{$count} ({$size}) — Core Base Layers (Immune)";
                        // We intentionally exclude this from $totalBytes since it physically cannot be reclaimed while the server is alive
                    } else {
                        $data[$key] = "{$count} ({$size}) — {$reclaimable} reclaimable";
                        $totalBytes += $this->parseSizeString($reclaimable);
                    }
                }
            }
        }

        if ($totalBytes > 0) {
            $data['total'] = $this->formatBytes($totalBytes) . ' reclaimable';
        }

        return $data;
    }

    /**
     * Parse a human-readable size string like "1.2GB" into bytes.
     */
    protected function parseSizeString(string $size): int
    {
        if (preg_match('/([\d.]+)\s*(B|KB|MB|GB|TB)/i', $size, $m)) {
            $value = (float) $m[1];
            return (int) match(strtoupper($m[2])) {
                'TB' => $value * 1099511627776,
                'GB' => $value * 1073741824,
                'MB' => $value * 1048576,
                'KB' => $value * 1024,
                default => $value,
            };
        }
        return 0;
    }

    /**
     * Get a full snapshot of all metrics.
     */
    public function getFullSnapshot(): array
    {
        return [
            'disk'         => $this->getDiskUsage(),
            'memory'       => $this->getMemoryUsage(),
            'cpu'          => $this->getCpuLoad(),
            'uptime'       => $this->getUptime(),
            'cleanup'      => $this->getCleanupSettings(),
            'applications' => $this->getApplications(),
            'timestamp'    => now()->toIso8601String(),
        ];
    }

    // ── Manual Cleanup Actions ─────────────────────────────

    /**
     * Estimate reclaimable space for each cleanup category.
     */
    public function getCleanupEstimates(): array
    {
        return [
            'laravel_cache' => $this->estimateLaravelCache(),
            'logs'          => $this->estimateLogs(),
            'backups'       => $this->estimateBackups(),
            'health_history'=> $this->estimateHealthHistory(),
            'traffic_metrics'=> $this->estimateTrafficMetrics(),
            'proxy_cache'   => $this->estimateProxyCache(),
        ];
    }

    /**
     * Clear Laravel framework caches (config, routes, views, events, compiled).
     */
    public function clearLaravelCaches(): array
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('optimize:clear');
            return ['success' => true, 'message' => 'All Laravel caches cleared.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Flush all non-critical Redis cache keys.
     */
    public function clearRedisCache(): array
    {
        try {
            \Illuminate\Support\Facades\Cache::flush();
            return ['success' => true, 'message' => 'Redis cache flushed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete Laravel log files older than 7 days.
     */
    public function purgeLogs(int $daysToKeep = 7): array
    {
        try {
            $logPath = storage_path('logs');
            $cutoff  = now()->subDays($daysToKeep)->timestamp;
            $deleted = 0;
            $freed   = 0;

            foreach (glob($logPath . '/*.log') as $file) {
                if (basename($file) === 'laravel.log' && $daysToKeep > 0) {
                    continue; // keep the current active log
                }
                if (filemtime($file) < $cutoff) {
                    $freed += filesize($file);
                    unlink($file);
                    $deleted++;
                }
            }

            return ['success' => true, 'message' => "Deleted {$deleted} log files, freed " . $this->formatBytes($freed) . '.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Prune old backups via Spatie backup:clean.
     */
    public function pruneBackups(): array
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('backup:clean', ['--no-interaction' => true]);
            $output = trim(\Illuminate\Support\Facades\Artisan::output());
            return ['success' => true, 'message' => $output ?: 'Old backups pruned.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete health check results older than N days.
     */
    public function purgeHealthHistory(int $daysToKeep = 30): array
    {
        try {
            $cutoff  = now()->subDays($daysToKeep);
            $deleted = \App\Models\HealthCheckResult::where('created_at', '<', $cutoff)->delete();
            return ['success' => true, 'message' => "Deleted {$deleted} health check records."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete traffic metrics older than N days.
     */
    public function purgeTrafficMetrics(int $daysToKeep = 30): array
    {
        try {
            $cutoff  = now()->subDays($daysToKeep);
            $deleted = \App\Models\TrafficMetric::where('created_at', '<', $cutoff)->delete();
            return ['success' => true, 'message' => "Deleted {$deleted} traffic metric records."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Clear proxy config disk snapshots.
     */
    public function clearProxyCache(): array
    {
        try {
            $dir = config('services.proxy.config_snapshot_dir', '/data/config-cache');
            if (! is_dir($dir)) {
                return ['success' => true, 'message' => 'Proxy cache directory not found (may be on another container).'];
            }

            $deleted = 0;
            $freed   = 0;
            foreach (glob($dir . '/*') as $file) {
                if (is_file($file)) {
                    $freed += filesize($file);
                    unlink($file);
                    $deleted++;
                }
            }

            return ['success' => true, 'message' => "Deleted {$deleted} snapshot files, freed " . $this->formatBytes($freed) . '.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Estimate helpers ────────────────────────────────────

    protected function estimateLaravelCache(): array
    {
        $size = 0;
        $dirs = [
            storage_path('framework/cache'),
            storage_path('framework/views'),
        ];

        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $size += $this->dirSize($dir);
            }
        }

        return ['size' => $size, 'formatted' => $this->formatBytes($size), 'label' => 'Laravel Caches'];
    }

    protected function estimateLogs(): array
    {
        $size  = 0;
        $count = 0;
        $cutoff = now()->subDays(7)->timestamp;
        $logPath = storage_path('logs');

        foreach (glob($logPath . '/*.log') as $file) {
            if (filemtime($file) < $cutoff && basename($file) !== 'laravel.log') {
                $size += filesize($file);
                $count++;
            }
        }

        return ['size' => $size, 'formatted' => $this->formatBytes($size), 'count' => $count, 'label' => 'Old Log Files (>7d)'];
    }

    protected function estimateBackups(): array
    {
        $size = 0;
        $backupPath = storage_path('app/' . config('backup.backup.name', env('APP_NAME', 'laravel-backup')));

        if (is_dir($backupPath)) {
            $size = $this->dirSize($backupPath);
        }

        return ['size' => $size, 'formatted' => $this->formatBytes($size), 'label' => 'Backup Files'];
    }

    protected function estimateHealthHistory(): array
    {
        $cutoff = now()->subDays(30);
        $count  = \App\Models\HealthCheckResult::where('created_at', '<', $cutoff)->count();

        return ['size' => 0, 'count' => $count, 'formatted' => "{$count} records", 'label' => 'Health History (>30d)'];
    }

    protected function estimateTrafficMetrics(): array
    {
        $cutoff = now()->subDays(30);
        $count  = \App\Models\TrafficMetric::where('created_at', '<', $cutoff)->count();

        return ['size' => 0, 'count' => $count, 'formatted' => "{$count} records", 'label' => 'Traffic Metrics (>30d)'];
    }

    protected function estimateProxyCache(): array
    {
        $dir = config('services.proxy.config_snapshot_dir', '/data/config-cache');
        $size = 0;

        if (is_dir($dir)) {
            $size = $this->dirSize($dir);
        }

        return ['size' => $size, 'formatted' => $this->formatBytes($size), 'label' => 'Proxy Config Cache'];
    }

    protected function dirSize(string $dir): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function hasCoolifyCredentials(): bool
    {
        return ! empty(config('services.coolify.api_token'))
            && ! empty(config('services.coolify.base_url'));
    }

    protected function getServerUuid(): ?string
    {
        // Fetch server list and pick the first one (usually localhost)
        $servers = $this->coolifyGet('/api/v1/servers');
        $list = $servers ?? [];

        foreach ($list as $server) {
            if (($server['is_coolify_host'] ?? false) === true) {
                return $server['uuid'];
            }
        }

        return $list[0]['uuid'] ?? null;
    }

    protected function coolifyGet(string $path): ?array
    {
        $response = Http::withHeaders($this->coolifyHeaders())
            ->timeout(10)
            ->get(config('services.coolify.base_url') . $path);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    protected function coolifyHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . config('services.coolify.api_token'),
            'Accept'        => 'application/json',
        ];
    }

    protected function diskStatus(float $percent): string
    {
        if ($percent >= 90) return 'critical';
        if ($percent >= 80) return 'warning';
        if ($percent >= 70) return 'caution';
        return 'healthy';
    }

    protected function memoryStatus(float $percent): string
    {
        if ($percent >= 90) return 'critical';
        if ($percent >= 80) return 'warning';
        return 'healthy';
    }

    protected function cpuStatus(float $loadPercent): string
    {
        if ($loadPercent >= 90) return 'critical';
        if ($loadPercent >= 70) return 'warning';
        return 'healthy';
    }

    protected function emptyDisk(): array
    {
        return ['total_bytes' => 0, 'used_bytes' => 0, 'free_bytes' => 0,
                'total_gb' => 0, 'used_gb' => 0, 'free_gb' => 0, 'percent' => 0, 'status' => 'unknown'];
    }

    protected function emptyMemory(): array
    {
        return ['total_mb' => 0, 'used_mb' => 0, 'free_mb' => 0, 'available_mb' => 0,
                'buffers_mb' => 0, 'cached_mb' => 0, 'percent' => 0, 'status' => 'unknown'];
    }
}
