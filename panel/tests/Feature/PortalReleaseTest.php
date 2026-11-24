<?php

use App\Api\Abilities;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\PortalReleases;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// A raw PUT, the way the launcher's workflow sends it. A null header is left out.
function portalPut(?string $token, string $version, string $body, array $headers = [], ?string $platform = null)
{
    $headers = ['Content-Type' => 'application/octet-stream', 'X-Signature' => 'c2lnbmF0dXJl', 'X-Sha256' => hash('sha256', $body), ...$headers];
    if ($token !== null) {
        $headers += bearer($token);
    }
    $server = [];
    foreach (array_filter($headers, fn ($value) => $value !== null) as $name => $value) {
        $server[strtolower($name) === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('PUT', "/api/v1/portal/releases/{$version}".($platform === null ? '' : "?platform={$platform}"), [], [], [], $server, $body);
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
            'platform' => 'windows-x86_64',
            'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.2.3-setup.exe',
            'size' => 15,
        ]);

    $disk = Storage::disk('local');
    expect($disk->get('portal/spawnpoint-portal-1.2.3-setup.exe'))->toBe('installer bytes')
        ->and(json_decode($disk->get('portal/spawnpoint-portal-1.2.3-setup.json'), true))
        ->toMatchArray(['signature' => 'c2lnL25hdHVyZT0=', 'sha256' => hash('sha256', 'installer bytes'), 'size' => 15])
        ->and($disk->allFiles('portal'))->toHaveCount(2)
        ->and(ActivityLog::sole()->only('event', 'properties'))->toBe(['event' => 'portal.release', 'properties' => ['version' => '1.2.3', 'platform' => 'windows-x86_64']]);
});

test('a version must be greater than the newest, compared per part', function () {
    portalPut($this->token, '1.9.0', 'a')->assertCreated();

    portalPut($this->token, '1.9.0', 'b')->assertStatus(409)->assertJsonPath('message', fn ($message) => str_contains($message, '1.9.0'));
    portalPut($this->token, '1.8.9', 'b')->assertStatus(409);
    portalPut($this->token, '1.10.0', 'c')->assertCreated();

    expect(Storage::disk('local')->get('portal/spawnpoint-portal-1.9.0-setup.exe'))->toBe('a');
});

test('old releases stay until someone removes them', function () {
    foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0'] as $version) {
        portalPut($this->token, $version, "build {$version}")->assertCreated();
    }

    expect(Storage::disk('local')->allFiles('portal'))->toHaveCount(8);
});

test('the versions page is public and lists every release, newest first', function () {
    portalPut($this->token, '1.0.0', 'old')->assertCreated();
    portalPut($this->token, '1.1.0', 'newer')->assertCreated();
    // The token's owner would otherwise still be signed in for the requests below.
    auth()->forgetGuards();

    $this->get('/portal/versions')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PortalVersions')
        ->where('canManage', false)
        ->has('releases', 2)
        ->where('releases.0.version', '1.1.0')
        ->where('releases.0.size', 5)
        ->where('releases.0.url', 'https://panel.example.com/portal/spawnpoint-portal-1.1.0-setup.exe')
        ->where('releases.1.version', '1.0.0'));
});

test('managing releases is for admins and for viewers who were given it', function () {
    portalPut($this->token, '1.0.0', 'a')->assertCreated();
    $viewer = User::factory()->create();
    // The token's owner would otherwise still be signed in for the requests below.
    auth()->forgetGuards();

    $this->put('/portal/versions/1.0.0', ['downloadable' => false])->assertRedirect(route('login'));
    $this->actingAs($viewer)->put('/portal/versions/1.0.0', ['downloadable' => false])->assertForbidden();
    $this->actingAs($viewer)->delete('/portal/versions/1.0.0')->assertForbidden();
    $this->actingAs($viewer)->get('/portal/versions')->assertInertia(fn (Assert $page) => $page->where('canManage', false));

    $viewer->forceFill(['manages_launcher' => true])->save();
    $this->actingAs($viewer)->get('/portal/versions')->assertInertia(fn (Assert $page) => $page->where('canManage', true));
    // An admin gets the buttons under Settings, not on the public page.
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/portal/versions')->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->actingAs($admin)->get(route('panel-settings.edit'))->assertInertia(fn (Assert $page) => $page->has('launcherReleases', 1)->where('launcherReleases.0.version', '1.0.0'));
    $this->actingAs($viewer)->put('/portal/versions/1.0.0', ['downloadable' => false])->assertRedirect();
    $this->actingAs($viewer)->put('/portal/versions/9.9.9', ['downloadable' => false])->assertNotFound();
});

test('a release that is not downloadable stays listed, is refused, and the updater gets the one before', function () {
    portalPut($this->token, '1.0.0', 'old', ['X-Signature' => 'b2xk'])->assertCreated();
    portalPut($this->token, '1.1.0', 'new', ['X-Signature' => 'bmV3'])->assertCreated();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/portal/versions/1.1.0', ['downloadable' => false])->assertRedirect();

    $this->get('/portal/versions')->assertInertia(fn (Assert $page) => $page->has('releases', 2)->where('releases.0.version', '1.1.0')->where('releases.0.url', null));
    $this->get('/portal/spawnpoint-portal-1.1.0-setup.exe')->assertNotFound();
    $this->get('/portal/latest.json')->assertJsonPath('version', '1.0.0')->assertJsonPath('platforms.windows-x86_64.signature', 'b2xk');
    $this->get('/portal/download')->assertRedirect('https://panel.example.com/portal/spawnpoint-portal-1.0.0-setup.exe');
    expect(PortalReleases::latestVersion())->toBe('1.0.0');
    // Its number stays taken: an upload must still be greater.
    portalPut($this->token, '1.1.0', 'again')->assertStatus(409);

    $this->actingAs($admin)->put('/portal/versions/1.1.0', ['downloadable' => true])->assertRedirect();
    $this->get('/portal/spawnpoint-portal-1.1.0-setup.exe')->assertOk();
    $this->get('/portal/latest.json')->assertJsonPath('version', '1.1.0')->assertJsonPath('platforms.windows-x86_64.signature', 'bmV3');
    expect(ActivityLog::query()->where('event', 'like', 'portal.%')->orderBy('id')->pluck('event')->all())
        ->toBe(['portal.release', 'portal.release', 'portal.withhold', 'portal.allow']);
});

test('removing a release deletes its files', function () {
    portalPut($this->token, '1.0.0', 'old')->assertCreated();
    portalPut($this->token, '1.1.0', 'new')->assertCreated();

    $this->actingAs(User::factory()->admin()->create())->delete('/portal/versions/1.1.0')->assertRedirect();

    expect(Storage::disk('local')->allFiles('portal'))->toEqualCanonicalizing(['portal/spawnpoint-portal-1.0.0-setup.exe', 'portal/spawnpoint-portal-1.0.0-setup.json'])
        ->and(PortalReleases::latestVersion())->toBe('1.0.0')
        ->and(ActivityLog::query()->latest('id')->first()->only('event', 'properties'))->toBe(['event' => 'portal.remove', 'properties' => ['version' => '1.1.0']]);
    $this->get('/portal/latest.json')->assertJsonPath('version', '1.0.0');
});

test('an admin ticks the launcher permission for a viewer, and it goes with the role', function () {
    $viewer = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->put(route('users.update', $viewer), ['role' => 'viewer', 'manages_launcher' => true])->assertRedirect();
    expect($viewer->refresh()->manages_launcher)->toBeTrue();

    $this->put(route('users.update', $viewer), ['role' => 'admin', 'manages_launcher' => true])->assertRedirect();
    expect($viewer->refresh()->manages_launcher)->toBeFalse();
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

test('the newest version takes one installer per system, and the updater is told of each', function () {
    $this->freezeTime();
    portalPut($this->token, '1.0.0', 'old')->assertCreated();
    portalPut($this->token, '1.1.0', 'windows', ['X-Signature' => 'd2lu'])->assertCreated();

    portalPut($this->token, '1.1.0', 'linux', ['X-Signature' => 'bGlu'], 'linux-x86_64')
        ->assertCreated()
        ->assertExactJson([
            'version' => '1.1.0',
            'platform' => 'linux-x86_64',
            'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.1.0-x86_64.AppImage',
            'size' => 5,
        ]);

    // Taken: the same system again, and a system for a version that is no longer the newest.
    portalPut($this->token, '1.1.0', 'again', [], 'linux-x86_64')->assertStatus(409);
    portalPut($this->token, '1.0.0', 'late', [], 'linux-x86_64')->assertStatus(409);
    portalPut($this->token, '1.2.0', 'x', [], 'darwin-aarch64')->assertUnprocessable();
    portalPut($this->token, '1.2.0', 'x', [], '../x')->assertUnprocessable();

    $this->get('/portal/latest.json')->assertExactJson([
        'version' => '1.1.0',
        'pub_date' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'platforms' => [
            'windows-x86_64' => ['signature' => 'd2lu', 'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.1.0-setup.exe'],
            'linux-x86_64' => ['signature' => 'bGlu', 'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.1.0-x86_64.AppImage'],
        ],
    ]);
    $appImage = $this->get('/portal/spawnpoint-portal-1.1.0-x86_64.AppImage')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($appImage->streamedContent())->toBe('linux');
    $this->get('/portal/spawnpoint-portal-1.0.0-x86_64.AppImage')->assertNotFound();
    $this->get('/portal/spawnpoint-portal-1.1.0-x86_64.json')->assertNotFound();
    expect(ActivityLog::query()->latest('id')->first()->properties)->toBe(['version' => '1.1.0', 'platform' => 'linux-x86_64']);
});

test('a visitor is offered the installer of their own system first', function () {
    portalPut($this->token, '1.0.0', 'old')->assertCreated();
    portalPut($this->token, '1.1.0', 'windows')->assertCreated();
    portalPut($this->token, '1.1.0', 'linux!', [], 'linux-x86_64')->assertCreated();
    auth()->forgetGuards();
    $linux = ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'];
    $android = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36'];

    $this->get('/portal/download', $linux)->assertRedirect('https://panel.example.com/portal/spawnpoint-portal-1.1.0-x86_64.AppImage');
    $this->get('/portal/download', $android)->assertRedirect('https://panel.example.com/portal/spawnpoint-portal-1.1.0-setup.exe');
    $this->get('/portal/download')->assertRedirect('https://panel.example.com/portal/spawnpoint-portal-1.1.0-setup.exe');

    $this->get('/portal/versions', $linux)->assertInertia(fn (Assert $page) => $page
        ->where('platform', 'linux-x86_64')
        ->where('releases.0.files', [
            ['platform' => 'windows-x86_64', 'size' => 7, 'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.1.0-setup.exe'],
            ['platform' => 'linux-x86_64', 'size' => 6, 'url' => 'https://panel.example.com/portal/spawnpoint-portal-1.1.0-x86_64.AppImage'],
        ])
        ->has('releases.1.files', 1));
    $this->get('/portal/versions')->assertInertia(fn (Assert $page) => $page->where('platform', 'windows-x86_64'));

    // A version made for Linux alone goes to Windows visitors too: it is the only one there is.
    portalPut($this->token, '1.2.0', 'linux', [], 'linux-x86_64')->assertCreated();
    auth()->forgetGuards();
    $this->get('/portal/download')->assertRedirect('https://panel.example.com/portal/spawnpoint-portal-1.2.0-x86_64.AppImage');
    $this->get('/portal/latest.json')->assertJsonMissingPath('platforms.windows-x86_64')->assertJsonPath('platforms.linux-x86_64.signature', 'c2lnbmF0dXJl');
});

test('withholding and removing a version cover every installer it has', function () {
    portalPut($this->token, '1.0.0', 'windows')->assertCreated();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/portal/versions/1.0.0', ['downloadable' => false])->assertRedirect();

    // An installer that arrives for a withheld version is withheld with it.
    portalPut($this->token, '1.0.0', 'linux', [], 'linux-x86_64')->assertCreated();
    $this->get('/portal/spawnpoint-portal-1.0.0-x86_64.AppImage')->assertNotFound();
    $this->get('/portal/latest.json')->assertNotFound();
    $this->get('/portal/versions')->assertInertia(fn (Assert $page) => $page->where('releases.0.url', null)->where('releases.0.files.1.url', null));

    $this->actingAs($admin)->put('/portal/versions/1.0.0', ['downloadable' => true])->assertRedirect();
    $this->get('/portal/spawnpoint-portal-1.0.0-x86_64.AppImage')->assertOk();
    $this->get('/portal/spawnpoint-portal-1.0.0-setup.exe')->assertOk();

    $this->actingAs($admin)->delete('/portal/versions/1.0.0')->assertRedirect();
    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);
});
