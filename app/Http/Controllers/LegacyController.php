<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keeps the two token-authenticated pre-Laravel endpoints working:
 *   api.php?action=webhook&token=…   (webhook URLs saved in GitHub / GitLab / Bitbucket)
 *   api.php?action=alert_action      (push notifications delivered before the upgrade)
 * Everything else moved to /api/… and needs a signed-in session.
 */
class LegacyController extends Controller
{
    public function __invoke(Request $request): mixed
    {
        return match ((string) $request->query('action', $request->input('action', ''))) {
            'webhook' => app()->call(WebhookController::class, ['token' => (string) $request->query('token', $request->input('token', ''))]),
            'alert_action' => $request->isMethod('post')
                ? app(AlertController::class)->decide($request)
                : new JsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405),
            default => new JsonResponse(['ok' => false, 'error' => 'This endpoint moved to /api. Please reload SSHhh.'], 410),
        };
    }
}
