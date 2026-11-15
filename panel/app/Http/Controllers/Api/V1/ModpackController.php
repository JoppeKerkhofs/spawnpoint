<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ModpackSearchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The same search the server forms use; the web controller already answers JSON.
 */
class ModpackController extends Controller
{
    public function search(Request $request, ModpackSearchController $controller): JsonResponse
    {
        return $controller($request);
    }

    public function versions(Request $request, ModpackSearchController $controller): JsonResponse
    {
        return $controller->versions($request);
    }
}
