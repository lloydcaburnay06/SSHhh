<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProjectRepository;
use App\Services\SshProfileRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SshProfileController extends Controller
{
    public function __construct(private SshProfileRepository $profiles, private ProjectRepository $projects)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['ok' => true, 'profiles' => $this->profiles->listAll()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'profile' => $this->profiles->create($request->all()),
            'profiles' => $this->profiles->listAll(),
        ], 201);
    }

    public function update(Request $request, int $profile): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'profile' => $this->profiles->update($profile, $request->all()),
            'profiles' => $this->profiles->listAll(),
            'projects' => $this->projects->listAll(),
        ]);
    }

    public function destroy(int $profile): JsonResponse
    {
        $this->profiles->delete($profile);

        return response()->json([
            'ok' => true,
            'profiles' => $this->profiles->listAll(),
            'projects' => $this->projects->listAll(),
        ]);
    }
}
