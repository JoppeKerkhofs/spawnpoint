<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Schedule;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    /**
     * The server's scheduled actions.
     */
    public function index(Server $server): JsonResponse
    {
        return response()->json(['data' => $server->schedules()->orderBy('time')->get()]);
    }

    /**
     * Add a scheduled backup, restart, start, stop or console command.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(Schedule::ACTIONS)],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            // The same limits the Rust service applies to a command.
            'command' => ['nullable', 'required_if:action,command', 'string', 'max:500', 'regex:/^[^-\x00-\x1F\x7F][^\x00-\x1F\x7F]*$/'],
        ]);
        abort_unless($request->user()->can('act', [$server, Schedule::permission($data['action'])]), 403);

        $schedule = $server->schedules()->create([
            ...$data,
            'command' => $data['action'] === 'command' ? ltrim((string) $data['command'], '/') : null,
        ]);
        ActivityLog::record('schedule.create', $server, array_filter(['action' => $data['action'], 'time' => $data['time']]));

        return response()->json(['data' => $schedule], 201);
    }

    /**
     * Pause or resume a schedule.
     */
    public function update(Request $request, Server $server, Schedule $schedule): JsonResponse
    {
        abort_unless($schedule->server_id === $server->id, 404);
        abort_unless($request->user()->can('act', [$server, Schedule::permission($schedule->action)]), 403);

        $schedule->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => $schedule]);
    }

    /**
     * Remove a schedule.
     */
    public function destroy(Request $request, Server $server, Schedule $schedule): Response
    {
        abort_unless($schedule->server_id === $server->id, 404);
        abort_unless($request->user()->can('act', [$server, Schedule::permission($schedule->action)]), 403);

        $schedule->delete();
        ActivityLog::record('schedule.delete', $server, ['action' => $schedule->action, 'time' => $schedule->time]);

        return response()->noContent();
    }
}
