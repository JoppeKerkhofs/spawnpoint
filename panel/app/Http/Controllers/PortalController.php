<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\PortalReleases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortalController extends Controller
{
    public function latest(PortalReleases $releases): JsonResponse
    {
        $all = $releases->downloadable();
        $version = array_key_first($all) ?? abort(404);
        $meta = $all[$version];

        return response()->json([
            'version' => $version,
            'pub_date' => $meta['uploaded_at'],
            'platforms' => ['windows-x86_64' => ['signature' => $meta['signature'], 'url' => PortalReleases::url($version)]],
        ])->header('Cache-Control', 'no-cache');
    }

    public function installer(string $version, PortalReleases $releases): BinaryFileResponse
    {
        abort_unless(isset($releases->downloadable()[$version]), 404);

        return response()->download(PortalReleases::path($version), PortalReleases::name($version), ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function download(PortalReleases $releases): RedirectResponse
    {
        return redirect(PortalReleases::url(array_key_first($releases->downloadable()) ?? abort(404)));
    }

    /**
     * Every release with its download link. Public.
     */
    public function versions(Request $request, PortalReleases $releases): Response
    {
        $user = $request->user();

        return Inertia::render('PortalVersions', [
            'releases' => $releases->listing(),
            // Admins manage them under Settings; a viewer who was given it cannot open that page.
            'canManage' => $user !== null && ! $user->isAdmin() && $user->can('manage-launcher'),
        ]);
    }

    public function update(Request $request, string $version, PortalReleases $releases): RedirectResponse
    {
        $downloadable = (bool) $request->validate(['downloadable' => ['required', 'boolean']])['downloadable'];
        $releases->mark($version, $releases->all()[$version] ?? abort(404), $downloadable);
        ActivityLog::record($downloadable ? 'portal.allow' : 'portal.withhold', properties: ['version' => $version]);

        return back();
    }

    public function destroy(string $version, PortalReleases $releases): RedirectResponse
    {
        abort_unless(isset($releases->all()[$version]), 404);
        $releases->delete($version);
        ActivityLog::record('portal.remove', properties: ['version' => $version]);

        return back();
    }
}
