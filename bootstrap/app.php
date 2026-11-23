<?php

use App\Http\Middleware\EnsureUnlocked;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\StripIndexPhp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(StripIndexPhp::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'unlocked' => EnsureUnlocked::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);

        // Token-authenticated endpoints called by Git hosts and the service worker
        $middleware->validateCsrfTokens(except: [
            'webhook',
            'webhook/*',
            'alert/decide',
            'api.php',
        ]);

        // Honour X-Forwarded-Proto from tunnels (secure cookies, push needs https),
        // but keep the client IP as REMOTE_ADDR so lockouts can't be dodged by a header.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The JSON API keeps the pre-Laravel envelope: { ok: false, error: "…" }
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*', 'webhook*', 'alert/decide', 'api.php')) {
                return null;
            }
            if ($e instanceof Illuminate\Validation\ValidationException) {
                return response()->json(['ok' => false, 'error' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }
            if ($e instanceof Illuminate\Session\TokenMismatchException) {
                return response()->json(['ok' => false, 'error' => 'Invalid or expired security token (CSRF). Please refresh the page.'], 419);
            }
            $status = match (true) {
                $e instanceof InvalidArgumentException => 422,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };
            if ($status === 500) {
                report($e);
            }

            return response()->json(['ok' => false, 'error' => $e->getMessage() ?: 'Server error.'], $status);
        });
    })->create();
