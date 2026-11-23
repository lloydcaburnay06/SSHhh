<?php

use Illuminate\Support\Facades\Schedule;

/*
| Optional: when `php artisan schedule:run` runs every minute (Task Scheduler / cron),
| this replaces the separate "SSHhh Log Watch" task. The command keeps its own
| file lock, so running both never overlaps a check.
*/
Schedule::command('sshhh:log-watch')->everyFiveMinutes();
