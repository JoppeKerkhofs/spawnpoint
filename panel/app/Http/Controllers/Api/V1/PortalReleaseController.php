<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\PortalReleases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PortalReleaseController extends Controller
{
    /**
     * Take the launcher's installer as the raw body, check it against its hash and publish it.
     */
    public function store(Request $request, string $version, PortalReleases $releases): JsonResponse
    {
        Validator::validate(
            ['version' => $version, 'signature' => $request->header('X-Signature'), 'sha256' => $request->header('X-Sha256')],
            [
                'version' => ['required', 'string', 'max:32', 'regex:'.PortalReleases::VERSION],
                'signature' => ['required', 'string', 'max:2000', 'regex:/^[A-Za-z0-9+\/=]+\z/'],
                'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}\z/'],
            ],
            [],
            ['signature' => 'X-Signature', 'sha256' => 'X-Sha256'],
        );

        // Symfony reads a form-encoded PUT into memory before this runs, so only a raw body is taken.
        abort_unless(str_starts_with((string) $request->header('Content-Type'), 'application/octet-stream'), 415, 'Send the installer as application/octet-stream.');

        $current = array_key_first($releases->all());
        abort_if($current !== null && version_compare($version, $current, '<='), 409, "The newest release is {$current}; upload a greater version.");

        $max = (int) config('panel.portal_max_bytes');
        $temp = $releases->receive($request->getContent(true), $max);
        abort_if($temp === null, 413, 'The installer is larger than '.intdiv($max, 1048576).' MB.');

        $size = filesize($temp) ?: 0;
        if ($size === 0 || ! hash_equals(strtolower((string) $request->header('X-Sha256')), (string) hash_file('sha256', $temp))) {
            unlink($temp);
            abort(422, 'The body does not match X-Sha256.');
        }

        $releases->publish($version, $temp, [
            'signature' => (string) $request->header('X-Signature'),
            'sha256' => strtolower((string) $request->header('X-Sha256')),
            'size' => $size,
            'uploaded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        ActivityLog::record('portal.release', properties: ['version' => $version]);

        return response()->json(['version' => $version, 'url' => PortalReleases::url($version), 'size' => $size], 201);
    }
}
