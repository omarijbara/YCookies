<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class RunSchedulerOnTraffic
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Add a 60-second lock to prevent checking the schedule on every single click
        if (!Cache::has('scheduler_last_run')) {
            Cache::put('scheduler_last_run', true, 60);

            // Dispatch schedule:run via Artisan in the background 
            // We use terminate/afterResponse hooks so it doesn't block the user's page load
            dispatch(function () {
                Artisan::call('schedule:run');
            })->afterResponse();
        }

        return $next($request);
    }
}
