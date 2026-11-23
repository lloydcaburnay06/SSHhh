<?php

namespace App\Http\Controllers;

use App\Services\EventLogger;
use App\Services\ProjectRepository;
use App\Services\SshProfileRepository;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private ProjectRepository $projects,
        private SshProfileRepository $profiles,
        private EventLogger $events,
    ) {
    }

    public function dashboard(): Response
    {
        return Inertia::render('dashboard', [
            'projects' => $this->projects->listAll(),
            'ssh_profiles' => $this->profiles->listAll(),
            'stats' => $this->projects->getDashboardStats(),
            'events' => $this->events->query(null, ['limit' => 30])['items'],
        ]);
    }

    public function project(int $project): Response|RedirectResponse
    {
        $row = $this->projects->find($project);
        if ($row === null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('project', [
            'project' => $row,
            'commands' => $this->projects->listCommands($project),
            'all_projects' => $this->projects->listAll(),
            'ssh_profiles' => $this->profiles->listAll(),
            'events' => $this->events->query($project, ['limit' => 60]),
        ]);
    }
}
