<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDomainLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $group = \Filament\Facades\Filament::getTenant();

        if ($group) {
            $limit = $group->domain_limit;

            if (
                $group->domains()->count() >= $limit &&
                !$group->onTrial('default') &&
                !$group->subscribedToPrice([
                    config('pricing.agency_monthly'),
                    config('pricing.enterprise'),
                ], 'default')
            ) {

                // Usually this redirects to the upgrade page. We'll fallback to a base URL or a filament page URI.
                return redirect()->to('/admin/billing-upgrade')
                    ->with('error', 'Upgrade required for additional domains');
            }
        }

        return $next($request);
    }
}
