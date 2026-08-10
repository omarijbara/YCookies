<?php

namespace App\Console\Commands;

use App\Models\Domain;
use Illuminate\Console\Command;

class ClearExpiredLegacyTokens extends Command
{
    protected $signature = 'domains:clear-expired-legacy-tokens';

    protected $description = 'Remove expired legacy origin auth tokens (post-rotation cleanup)';

    public function handle(): int
    {
        $count = Domain::whereNotNull('origin_auth_token_legacy')
            ->where('origin_auth_legacy_expires_at', '<', now())
            ->update([
                'origin_auth_token_legacy' => null,
                'origin_auth_legacy_expires_at' => null,
            ]);

        if ($count > 0) {
            $this->info("Cleared {$count} expired legacy token(s).");
        } else {
            $this->info('No expired legacy tokens found.');
        }

        return self::SUCCESS;
    }
}
