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
        $version = (string) (array_key_first($all) ?? abort(404));
        $installers = $all[$version];

        // A system the newest version has no installer for is left out, so it is offered no update.
        $platforms = [];
        $released = null;
        foreach ($installers as $platform => $meta) {
            $released ??= $meta['uploaded_at'];
            $platforms[$platform] = ['signature' => $meta['signature'], 'url' => PortalReleases::url($version, $platform)];
        }

        return response()->json(['version' => $version, 'pub_date' => $released, 'platforms' => $platforms])->header('Cache-Control', 'no-cache');
    }

    public function installer(string $version, string $platform, PortalReleases $releases): BinaryFileResponse
    {
        abort_unless(isset($releases->downloadable()[$version][$platform]), 404);

        return response()->download(PortalReleases::path($version, $platform), PortalReleases::name($version, $platform), ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    // The newest installer for the visitor's system, or the first there is.
    public function download(Request $request, PortalReleases $releases): RedirectResponse
    {
        $all = $releases->downloadable();
        $version = (string) (array_key_first($all) ?? abort(404));
        $platform = PortalReleases::platformOf($request->userAgent());

        return redirect(PortalReleases::url($version, isset($all[$version][$platform]) ? $platform : (string) array_key_first($all[$version])));
    }

    /**
     * Every release with its download link. Public.
     */
    public function versions(Request $request, PortalReleases $releases): Response
    {
        $user = $request->user();

        return Inertia::render('PortalVersions', [
            'releases' => $releases->listing(),
            // The visitor's system, whose installer the page offers first.
            'platform' => PortalReleases::platformOf($request->userAgent()),
            // Admins manage them under Settings; a viewer who was given it cannot open that page.
            'canManage' => $user !== null && ! $user->isAdmin() && $user->can('manage-launcher'),
        ]);
    }

    public function update(Request $request, string $version, PortalReleases $releases): RedirectResponse
    {
        $downloadable = (bool) $request->validate(['downloadable' => ['required', 'boolean']])['downloadable'];
        abort_unless(isset($releases->all()[$version]), 404);
        $releases->mark($version, $downloadable);
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
