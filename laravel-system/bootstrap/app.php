<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust forwarded host/protocol/IP headers only from explicitly listed
        // reverse-proxy IPs/CIDRs. Empty means no forwarded headers are trusted.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));
        foreach ($trustedProxies as $proxy) {
            if (in_array($proxy, ['*', '0.0.0.0/0', '::/0'], true)) {
                throw new \\RuntimeException('TRUSTED_PROXIES must not contain a wildcard or unrestricted network range.');
            }
            $parts = explode('/', $proxy, 2);
            $ipVersion = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4
                : (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 0);
            $maxPrefix = $ipVersion === 4 ? 32 : 128;
            if ($ipVersion === 0
                || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $maxPrefix))
            ) {
                throw new \\RuntimeException('TRUSTED_PROXIES must contain valid IP addresses or CIDR ranges.');
            }
        }
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->web(append: [
            EnsureInstalled::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'payments/webhook/*',
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
