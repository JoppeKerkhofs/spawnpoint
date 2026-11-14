<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Schedule;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    /**
     * Add a scheduled backup, restart, start, stop or console command.
     */
    public function store(Request $request, Server $server): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(Schedule::ACTIONS)],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            // The same limits the Rust service applies to a command.
            'command' => ['nullable', 'required_if:action,command', 'string', 'max:500', 'regex:/^[^-\x00-\x1F\x7F][^\x00-\x1F\x7F]*$/'],
        ]);

        $server->schedules()->create([
            ...$data,
            'command' => $data['action'] === 'command' ? ltrim((string) $data['command'], '/') : null,
        ]);
        ActivityLog::record('schedule.create', $server, array_filter(['action' => $data['action'], 'time' => $data['time']]));

        return back();
    }

    /**
     * Pause or resume a schedule.
     */
    public function update(Request $request, Server $server, Schedule $schedule): RedirectResponse
    {
        abort_unless($schedule->server_id === $server->id, 404);

        $schedule->update($request->validate(['is_active' => ['required', 'boolean']]));

        return back();
    }

    /**
     * Remove a schedule.
     */
    public function destroy(Server $server, Schedule $schedule): RedirectResponse
    {
        abort_unless($schedule->server_id === $server->id, 404);

        $schedule->delete();
        ActivityLog::record('schedule.delete', $server, ['action' => $schedule->action, 'time' => $schedule->time]);

        return back();
    }
}
