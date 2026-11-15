<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\CloudflareDns;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    /**
     * Show who did what, newest first.
     */
    public function index(Request $request): Response
    {
        $server = $request->string('server')->toString();
        $page = ActivityLog::query()
            ->when($server !== '', fn ($query) => $query->where('subject', $server))
            ->latest('id')
            ->simplePaginate(50)
            ->withQueryString();

        return Inertia::render('Activity', [
            'entries' => collect($page->items())->map(fn (ActivityLog $entry): array => [
                'id' => $entry->id,
                'actor' => $entry->actor,
                'event' => $entry->event,
                'subject' => $entry->subject,
                'properties' => $entry->properties,
                'at' => $entry->created_at->toIso8601String(),
            ]),
            'server' => $server,
            // Servers whose failed DNS record can be tried again: the ones that still exist, while DNS is set up.
            'dnsRetry' => CloudflareDns::enabled() ? Server::query()->pluck('slug') : [],
            'newerUrl' => $page->previousPageUrl(),
            'olderUrl' => $page->nextPageUrl(),
        ]);
    }
}
