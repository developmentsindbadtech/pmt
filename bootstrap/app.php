<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // When behind GCP (or any) load balancer: set TRUSTED_PROXIES=* in .env so
        // Laravel trusts X-Forwarded-* headers (correct client IP, scheme, host).
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies !== null && $proxies !== '') {
            $at = $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
            $middleware->trustProxies(at: $at);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Custom error pages are automatically used from resources/views/errors/{status}.blade.php
        // Laravel will automatically render 404.blade.php, 500.blade.php, 403.blade.php, etc.
    })->create();
