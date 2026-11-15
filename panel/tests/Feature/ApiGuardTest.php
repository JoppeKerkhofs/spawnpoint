<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

test('every api route declares which ability it needs', function () {
    // These are the only ones a plain valid token may call: who am I, and the pairing handshake.
    $exempt = ['GET api/v1/me', 'POST api/v1/pair', 'POST api/v1/pair/token'];
    $unguarded = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') && $route->uri() !== 'api/v1') {
            continue;
        }

        $name = $route->methods()[0].' '.$route->uri();
        $guarded = collect($route->gatherMiddleware())->contains(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'ability:'));

        if (! $guarded && ! in_array($name, $exempt, true)) {
            $unguarded[] = $name;
        }
    }

    expect($unguarded)->toBe([]);
});

test('an api token may make 120 requests a minute, counted per token', function () {
    $request = Request::create('/api/v1/servers');
    $request->headers->set('Authorization', 'Bearer 1|abc');

    $limit = RateLimiter::limiter('api')($request);

    // Two tokens of one user each get their own budget.
    expect($limit->maxAttempts)->toBe(120)->and($limit->key)->toBe(hash('sha256', '1|abc'));
});

test('a token that may only read files gets a download address and no upload address', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    $admin = User::factory()->admin()->create();

    $this->getJson('/api/v1/servers/survival/files/transfer', bearer($admin->createToken('Read', ['files:read'])->plainTextToken))
        ->assertOk()->assertJsonStructure(['download'])->assertJsonMissingPath('upload');

    // As before 1.5.0: a token that may write gets both.
    $this->getJson('/api/v1/servers/survival/files/transfer', bearer($admin->createToken('Write', ['files:write'])->plainTextToken))
        ->assertOk()->assertJsonStructure(['upload', 'download']);

    $this->getJson('/api/v1/servers/survival/files/transfer', bearer($admin->createToken('Logs', ['logs:read'])->plainTextToken))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the files:read ability.');
});
