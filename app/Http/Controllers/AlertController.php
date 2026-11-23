<?php

namespace App\Http\Controllers;

use App\Services\ErrorAlertManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Error alert decisions opened from a push notification. Works without signing in:
 * the random single-use token in the link (valid 24 hours) is the permission.
 */
class AlertController extends Controller
{
    public function __construct(private ErrorAlertManager $alerts)
    {
    }

    public function show(Request $request): SymfonyResponse
    {
        $token = (string) $request->query('t', '');
        $view = null;
        $error = null;
        try {
            $view = $this->alerts->viewByToken($token);
            if ($view === null) {
                $error = 'This alert link is invalid or older than 24 hours.';
            }
        } catch (Throwable $e) {
            $error = 'SSHhh could not load this alert: '.$e->getMessage();
        }

        return Inertia::render('alert', ['token' => $token, 'alert' => $view, 'error' => $error])
            ->toResponse($request)
            ->withHeaders([
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex',
            ]);
    }

    public function decide(Request $request): JsonResponse
    {
        $via = in_array($request->input('via'), ['push', 'page'], true) ? (string) $request->input('via') : 'push';
        try {
            return response()->json($this->alerts->decide(
                (string) $request->input('token', ''),
                (string) $request->input('choice', ''),
                $via,
            ));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
