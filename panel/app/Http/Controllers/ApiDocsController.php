<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiDocsController extends Controller
{
    // The fields of the OpenAPI document that hold a text people read.
    private const array TEXTS = ['summary', 'description'];

    /**
     * Show the API reference, or with a ".json" address return the OpenAPI document it is made from.
     */
    public function __invoke(Request $request): Response|JsonResponse
    {
        $spec = self::spec(fn (string $text): string => (string) __($text));

        return $request->routeIs('api-docs.spec')
            ? response()->json($spec)
            : Inertia::render('ApiDocs', ['spec' => $spec, 'baseUrl' => url('/api/v1')]);
    }

    /**
     * The OpenAPI document, with every text people read passed through the given function.
     * lang:sync uses it to collect those texts; the pages use it to translate them.
     *
     * @param  (callable(string): string)|null  $text
     * @return array<string, mixed>
     */
    public static function spec(?callable $text = null): array
    {
        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents(resource_path('api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

        return $text === null ? $spec : self::walk($spec, $text);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): string  $text
     * @return array<string, mixed>
     */
    private static function walk(array $node, callable $text): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::walk($value, $text);
            } elseif (is_string($value) && in_array($key, self::TEXTS, true)) {
                $node[$key] = $text($value);
            }
        }

        return $node;
    }
}
