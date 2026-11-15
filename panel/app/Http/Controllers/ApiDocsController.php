<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiDocsController extends Controller
{
    /**
     * Show the API reference, or with a ".json" address return the OpenAPI document it is made from.
     */
    public function __invoke(Request $request): Response|JsonResponse
    {
        $spec = json_decode((string) file_get_contents(resource_path('api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

        return $request->routeIs('api-docs.spec')
            ? response()->json($spec)
            : Inertia::render('ApiDocs', ['spec' => $spec, 'baseUrl' => url('/api/v1')]);
    }
}
