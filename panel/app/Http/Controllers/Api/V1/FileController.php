<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileRequest;
use App\Http\Requests\LogRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Make a folder inside the server's data folder.
     */
    public function folder(FileRequest $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->makeFolder($server, $request->path());
        ActivityLog::record('file.folder', $server, ['path' => $request->path()]);

        return response()->json(['message' => __('The folder was created.')], 201);
    }

    /**
     * Rename or move a file inside the server's data folder.
     */
    public function rename(FileRequest $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->renameFile($server, $request->path('from'), $request->path('to'));
        ActivityLog::record('file.rename', $server, ['from' => $request->path('from'), 'to' => $request->path('to')]);

        return response()->json(['message' => __('Renamed.')]);
    }

    /**
     * A whole log file as plain text. Logs are read over the node's signed API, not with a
     * browser token, so the panel passes the content on itself.
     */
    public function log(LogRequest $request, Server $server): StreamedResponse
    {
        $file = $request->validated('file') ?? abort(404);
        $content = (new NodeClient($server->node))->log($server, $file);

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $server->slug.'-'.preg_replace('/\.gz$/', '', $file),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
