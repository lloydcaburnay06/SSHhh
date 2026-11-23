<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifier;
use App\Services\ProjectRepository;
use App\Services\SettingsRepository;
use App\Services\SshProfileRepository;
use App\Services\VaultBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Outbound deploy notifications and the encrypted vault backup.
 */
class SettingsController extends Controller
{
    public function notifications(SettingsRepository $settings): JsonResponse
    {
        return response()->json(['ok' => true, 'settings' => $settings->getNotificationConfigPublic()]);
    }

    public function saveNotifications(Request $request, SettingsRepository $settings): JsonResponse
    {
        return response()->json(['ok' => true, 'settings' => $settings->saveNotificationConfig($request->all())]);
    }

    public function testNotification(Notifier $notifier): JsonResponse
    {
        $notifier->sendTest();

        return response()->json(['ok' => true, 'message' => 'Test notification delivered successfully.']);
    }

    public function exportVault(Request $request, VaultBackup $vault): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'filename' => 'sshhh-vault-'.date('Ymd-His').'.json',
            'bundle' => $vault->exportEncryptedVault((string) $request->input('passphrase', '')),
        ]);
    }

    public function importVault(
        Request $request,
        VaultBackup $vault,
        ProjectRepository $projects,
        SshProfileRepository $profiles,
    ): JsonResponse {
        $bundle = $request->input('bundle');
        if (is_string($bundle)) {
            $bundle = json_decode($bundle, true);
        }
        if (! is_array($bundle)) {
            throw new InvalidArgumentException('Valid encrypted vault JSON bundle is required.');
        }

        return response()->json([
            'ok' => true,
            'summary' => $vault->importEncryptedVault($bundle, (string) $request->input('passphrase', ''), $request->boolean('overwrite')),
            'projects' => $projects->listAll(),
            'profiles' => $profiles->listAll(),
            'stats' => $projects->getDashboardStats(),
        ]);
    }
}
