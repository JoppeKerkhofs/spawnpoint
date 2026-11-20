<?php

use App\Api\Abilities;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

// A raw PUT, the way the launcher's workflow sends it. A null header is left out.
function portalPut(?string $token, string $version, string $body, array $headers = [])
{
    $headers = ['Content-Type' => 'application/octet-stream', 'X-Signature' => 'c2lnbmF0dXJl', 'X-Sha256' => hash('sha256', $body), ...$headers];
    if ($token !== null) {
        $headers += bearer($token);
    }
    $server = [];
    foreach (array_filter($headers, fn ($value) => $value !== null) as $name => $value) {
        $server[strtolower($name) === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('PUT', "/api/v1/portal/releases/{$version}", [], [], [], $server, $body);
}

function offeredAbilities(User $user): array
{
    return collect(Abilities::offeredTo($user)['groups'])->flatMap(fn ($group) => array_column($group['abilities'], 'name'))->all();
}

beforeEach(function () {
    Storage::fake('local');
    config(['app.url' => 'https://panel.example.com']);
    $this->token = User::factory()->admin()->create()->createToken('CI', ['portal:release'])->plainTextToken;
});

test('an upload needs a token, the ability and an admin owner', function () {
    portalPut(null, '1.0.0', 'x')->assertUnauthorized();

    $noAbility = User::factory()->admin()->create()->createToken('CI', ['servers:read'])->plainTextToken;
    portalPut($noAbility, '1.0.0', 'x')->assertForbidden();

    $viewer = User::factory()->create()->createToken('CI', ['portal:release'])->plainTextToken;
    portalPut($viewer, '1.0.0', 'x')->assertForbidden();

    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);
});

test('the ability is offered to admins only', function () {
    expect(offeredAbilities(User::factory()->admin()->create()))->toContain('portal:release')
        ->and(offeredAbilities(User::factory()->create()))->not->toContain('portal:release');
});

test('an upload is refused for a bad version, signature or hash, and leaves nothing behind', function (string $version, array $headers) {
    portalPut($this->token, $version, 'installer', $headers)->assertUnprocessable();

    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);
})->with([
    'two parts' => ['1.2', []],
    'four parts' => ['1.2.3.4', []],
    'a prefix' => ['v1.2.3', []],
    'a trailing newline' => ['1.2.3%0A', []],
    'no signature' => ['1.0.0', ['X-Signature' => null]],
    'a signature with a space' => ['1.0.0', ['X-Signature' => 'abc def']],
    'a signature too long' => ['1.0.0', ['X-Signature' => str_repeat('a', 2001)]],
    'no hash' => ['1.0.0', ['X-Sha256' => null]],
    'a short hash' => ['1.0.0', ['X-Sha256' => 'abc']],
    'a wrong hash' => ['1.0.0', ['X-Sha256' => str_repeat('0', 64)]],
]);

test('an empty body is refused', function () {
    portalPut($this->token, '1.0.0', '')->assertUnprocessable();

    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);
});

test('the body must be sent raw', function () {
    portalPut($this->token, '1.0.0', 'installer', ['Content-Type' => 'multipart/form-data'])->assertStatus(415);
});

test('an upload over the limit is refused with 413 and nothing is kept', function () {
    config(['panel.portal_max_bytes' => 10]);

    portalPut($this->token, '1.0.0', str_repeat('a', 11))->assertStatus(413);
    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);

    portalPut($this->token, '1.0.0', str_repeat('a', 10))->assertCreated();
});

test('a good upload is stored with its signature, hash and size', function () {
    portalPut($this->token, '1.2.3', 'installer bytes', ['X-Signature' => 'c2lnL25hdHVyZT0='])
        ->assertCreated()
        ->assertExactJson([
            'version' => '1.2.3',
            'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.2.3-setup.exe',
            'size' => 15,
        ]);

    $disk = Storage::disk('local');
    expect($disk->get('portal/spawnpoint-portal-1.2.3-setup.exe'))->toBe('installer bytes')
        ->and(json_decode($disk->get('portal/spawnpoint-portal-1.2.3-setup.json'), true))
        ->toMatchArray(['signature' => 'c2lnL25hdHVyZT0=', 'sha256' => hash('sha256', 'installer bytes'), 'size' => 15])
        ->and($disk->allFiles('portal'))->toHaveCount(2)
        ->and(ActivityLog::sole()->only('event', 'properties'))->toBe(['event' => 'portal.release', 'properties' => ['version' => '1.2.3']]);
});

test('a version must be greater than the newest, compared per part', function () {
    portalPut($this->token, '1.9.0', 'a')->assertCreated();

    portalPut($this->token, '1.9.0', 'b')->assertStatus(409)->assertJsonPath('message', fn ($message) => str_contains($message, '1.9.0'));
    portalPut($this->token, '1.8.9', 'b')->assertStatus(409);
    portalPut($this->token, '1.10.0', 'c')->assertCreated();

    expect(Storage::disk('local')->get('portal/spawnpoint-portal-1.9.0-setup.exe'))->toBe('a');
});

test('only the three newest releases are kept', function () {
    foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0'] as $version) {
        portalPut($this->token, $version, "build {$version}")->assertCreated();
    }

    expect(Storage::disk('local')->allFiles('portal'))->toEqualCanonicalizing([
        'portal/spawnpoint-portal-1.1.0-setup.exe', 'portal/spawnpoint-portal-1.1.0-setup.json',
        'portal/spawnpoint-portal-1.2.0-setup.exe', 'portal/spawnpoint-portal-1.2.0-setup.json',
        'portal/spawnpoint-portal-1.3.0-setup.exe', 'portal/spawnpoint-portal-1.3.0-setup.json',
    ]);
});

test('latest.json is 404 until something is uploaded, then has the updater shape', function () {
    $this->get('/portal/latest.json')->assertNotFound();

    portalPut($this->token, '1.0.0', 'old', ['X-Signature' => 'b2xk'])->assertCreated();
    $this->travelTo('2026-11-20 19:00:00');
    portalPut($this->token, '1.2.3', 'new', ['X-Signature' => 'bmV3'])->assertCreated();

    // The address comes from the configuration, not from the Host header.
    $response = $this->withHeader('Host', 'evil.example')->get('/portal/latest.json');

    $response->assertOk()->assertExactJson([
        'version' => '1.2.3',
        'pub_date' => '2026-11-20T19:00:00Z',
        'platforms' => ['windows-x86_64' => [
            'signature' => 'bmV3',
            'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.2.3-setup.exe',
        ]],
    ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->all('set-cookie'))->toBe([]);
});

test('an installer downloads as a file, and only a stored one', function () {
    portalPut($this->token, '1.2.3', 'installer bytes')->assertCreated();

    $response = $this->get('/portal/spawnpoint-portal-1.2.3-setup.exe');

    $response->assertOk();
    expect($response->streamedContent())->toBe('installer bytes')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Length'))->toBe('15')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->all('set-cookie'))->toBe([]);

    foreach (['spawnpoint-portal-9.9.9-setup.exe', 'spawnpoint-portal-1.2.3-setup.json', 'spawnpoint-portal-1.2-setup.exe', 'other.exe', '..%2F..%2F.env', '%2e%2e/%2e%2e/.env', 'latest.json.bak'] as $name) {
        $this->get("/portal/{$name}")->assertNotFound();
    }
});

test('the download link goes to the newest installer', function () {
    $this->get('/portal/download')->assertNotFound();

    portalPut($this->token, '1.0.0', 'a')->assertCreated();
    portalPut($this->token, '1.1.0', 'b')->assertCreated();

    $this->get('/portal/download')->assertStatus(302)->assertRedirect('https://panel.example.com/portal/spawnpoint-portal-1.1.0-setup.exe');
});

test('the public addresses are limited per address', function () {
    foreach (range(1, 60) as $_) {
        $this->get('/portal/latest.json')->assertNotFound();
    }

    $this->get('/portal/latest.json')->assertTooManyRequests();
    $this->get('/portal/download')->assertTooManyRequests();
});

test('the image lets a request through that is as large as the cap', function () {
    $ini = parse_ini_file(base_path('../docker/php.ini'));

    expect(ini_parse_quantity($ini['post_max_size'] ?? '0'))->toBeGreaterThan(config('panel.portal_max_bytes'));
});

test('a large declared length reaches the controller when post_max_size allows it', function () {
    $limit = (int) ini_parse_quantity((string) ini_get('post_max_size'));
    $declared = min(25 * 1024 * 1024, $limit - 1);

    $server = ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => $declared, 'HTTP_X_SIGNATURE' => 'c2ln', 'HTTP_X_SHA256' => str_repeat('0', 64), 'HTTP_AUTHORIZATION' => bearer($this->token)['Authorization']];
    test()->call('PUT', '/api/v1/portal/releases/1.0.0', [], [], [], $server, 'small')->assertUnprocessable();
});

test('a second upload while one is being published is refused with 423', function () {
    $lock = Cache::lock('portal-release', 30);
    expect($lock->get())->toBeTrue();

    portalPut($this->token, '1.0.0', 'x')->assertStatus(423);
    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);

    $lock->release();
    portalPut($this->token, '1.0.0', 'x')->assertCreated();
    // The lock is released on the way out, also after a refusal.
    portalPut($this->token, '1.0.0', 'x')->assertStatus(409);
    portalPut($this->token, '1.0.1', 'x')->assertCreated();
});

test('a publish clears old temporary files and installers without metadata, never a listed release', function () {
    $disk = Storage::disk('local');
    $disk->put('portal/.upload-old', 'cut off');
    $disk->put('portal/.upload-young', 'running');
    $disk->put('portal/spawnpoint-portal-0.5.0-setup.exe', 'no metadata');
    touch($disk->path('portal/.upload-old'), now()->subHours(2)->getTimestamp());

    portalPut($this->token, '1.0.0', 'listed')->assertCreated();

    expect($disk->allFiles('portal'))->toEqualCanonicalizing([
        'portal/.upload-young',
        'portal/spawnpoint-portal-1.0.0-setup.exe', 'portal/spawnpoint-portal-1.0.0-setup.json',
    ]);
});

test('a version part of more than six digits is refused', function () {
    portalPut($this->token, '99999999999999999999.0.0', 'x')->assertUnprocessable();
    portalPut($this->token, '1.0.1234567', 'x')->assertUnprocessable();
    portalPut($this->token, '999999.999999.999999', 'x')->assertCreated();

    $this->get('/portal/spawnpoint-portal-99999999999999999999.0.0-setup.exe')->assertNotFound();
    expect(collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => str_contains($route->uri(), 'setup.exe'))->wheres['version'])->toBe('\d{1,6}\.\d{1,6}\.\d{1,6}');
});

test('the installer is sent with nosniff', function () {
    portalPut($this->token, '1.0.0', 'x')->assertCreated();

    $this->get('/portal/spawnpoint-portal-1.0.0-setup.exe')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('no preset carries the release ability, but an admin can still choose it', function () {
    expect(Abilities::presets()['full'])->not->toContain('portal:release');
    foreach (Abilities::presets() as $preset) {
        expect($preset)->not->toContain('portal:release');
    }

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('api-tokens.store'), ['name' => 'CI', 'abilities' => ['portal:release']])->assertSessionHasNoErrors();

    expect($admin->tokens()->sole()->abilities)->toBe(['portal:release']);
});

test('signed-in pages carry the newest launcher version once there is one, and an upload shows at once', function () {
    $this->actingAs(User::factory()->create());
    $shared = fn () => $this->get(route('dashboard'))->viewData('page')['props']['launcherVersion'] ?? null;

    expect($shared())->toBeNull();

    portalPut($this->token, '1.0.0', 'a')->assertCreated();
    expect($shared())->toBe('1.0.0');

    portalPut($this->token, '1.1.0', 'b')->assertCreated();
    expect($shared())->toBe('1.1.0');
});

test('a signed-out page does not carry the launcher version', function () {
    portalPut($this->token, '1.0.0', 'a')->assertCreated();

    app('auth')->forgetGuards();

    expect($this->get(route('login'))->viewData('page')['props']['launcherVersion'])->toBeNull();
});
