<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\PortalReleases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PortalReleaseController extends Controller
{
    /**
     * Take the launcher's installer as the raw body, check it against its hash and publish it.
     */
    public function store(Request $request, string $version, PortalReleases $releases): JsonResponse
    {
        // Without a platform it is Windows: what the launcher's workflow sent before there were others.
        $platform = $request->query('platform', PortalReleases::WINDOWS);

        Validator::validate(
            ['version' => $version, 'platform' => $platform, 'signature' => $request->header('X-Signature'), 'sha256' => $request->header('X-Sha256')],
            [
                'version' => ['required', 'string', 'regex:'.PortalReleases::VERSION],
                'platform' => ['required', 'string', Rule::in(array_keys(PortalReleases::PLATFORMS))],
                'signature' => ['required', 'string', 'max:2000', 'regex:/^[A-Za-z0-9+\/=]+\z/'],
                'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}\z/'],
            ],
            [],
            ['signature' => 'X-Signature', 'sha256' => 'X-Sha256'],
        );

        // Symfony reads a form-encoded PUT into memory before this runs, so only a raw body is taken.
        abort_unless(str_starts_with((string) $request->header('Content-Type'), 'application/octet-stream'), 415, 'Send the installer as application/octet-stream.');

        // One upload at a time: the version check and the publish must not interleave. Released on every path.
        $response = Cache::lock('portal-release', 1800)->get(fn () => $this->publish($request, $version, $platform, $releases));
        abort_if($response === false, 423, 'Another release is being uploaded; try again in a moment.');

        return $response;
    }

    private function publish(Request $request, string $version, string $platform, PortalReleases $releases): JsonResponse
    {
        // The newest version may still get the installer of a system it has none for.
        $all = $releases->all();
        $current = (string) array_key_first($all);
        $taken = $all !== [] && (version_compare($version, $current, '<') || isset($all[$version][$platform]));
        abort_if($taken, 409, "The newest release is {$current}; upload a greater version.");

        $max = (int) config('panel.portal_max_bytes');
        $temp = $releases->receive($request->getContent(true), $max);
        abort_if($temp === null, 413, 'The installer is larger than '.intdiv($max, 1048576).' MB.');

        $size = filesize($temp) ?: 0;
        if ($size === 0 || ! hash_equals(strtolower((string) $request->header('X-Sha256')), (string) hash_file('sha256', $temp))) {
            unlink($temp);
            abort(422, 'The body does not match X-Sha256.');
        }

        $releases->publish($version, $platform, $temp, [
            'signature' => (string) $request->header('X-Signature'),
            'sha256' => strtolower((string) $request->header('X-Sha256')),
            'size' => $size,
            'uploaded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        ActivityLog::record('portal.release', properties: ['version' => $version, 'platform' => $platform]);

        return response()->json(['version' => $version, 'platform' => $platform, 'url' => PortalReleases::url($version, $platform), 'size' => $size], 201);
    }
}
