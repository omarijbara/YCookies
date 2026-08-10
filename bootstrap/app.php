<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Wildcard is required: Traefik runs in the shared Docker 'coolify'
        // network where container IPs are dynamic. X-Forwarded-* headers
        // are trusted because Traefik is the only ingress point (ports 80/443).
        // If migrating away from Coolify/Traefik, restrict to actual proxy CIDRs.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'domain.limit' => \App\Http\Middleware\CheckDomainLimit::class,
            'script.blocker' => \App\Http\Middleware\ScriptBlockerMiddleware::class,
            'content.blocker' => \App\Http\Middleware\ContentBlockerMiddleware::class,
            'proxy.hmac' => \App\Http\Middleware\VerifyProxyHmac::class,
            'proxy.config.signature' => \App\Http\Middleware\VerifyProxyConfigSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);
        
        $exceptions->reportable(function (\Throwable $e) {
            \App\Services\CrashReporter::report($e);
        });
    })->create();
