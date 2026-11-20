<?php

namespace App\Http\Controllers;

use App\Services\PortalReleases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortalController extends Controller
{
    public function latest(PortalReleases $releases): JsonResponse
    {
        $all = $releases->all();
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
        abort_unless(isset($releases->all()[$version]), 404);

        return response()->download(PortalReleases::path($version), PortalReleases::name($version), ['Content-Type' => 'application/octet-stream']);
    }

    public function download(PortalReleases $releases): RedirectResponse
    {
        return redirect(PortalReleases::url(array_key_first($releases->all()) ?? abort(404)));
    }
}
