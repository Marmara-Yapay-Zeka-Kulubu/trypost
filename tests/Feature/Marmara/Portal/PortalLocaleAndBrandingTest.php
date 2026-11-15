<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Http\Middleware\App\SetLocale;
use App\Marmara\Portal\PortalConfig;
use App\Marmara\Portal\SetPortalLocale;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('marmara-portal.default_locale', 'tr');
    config()->set('marmara-portal.branding', [
        'enabled' => false,
        'name' => 'Marmara Yapay Zeka Kulübü',
        'tagline' => 'Sosyal Medya Paneli',
        'logo' => null,
        'logo_dark' => null,
        'accent' => null,
        'source_url' => null,
    ]);
});

test('the web stack resolves the portal locale middleware in place of upstream SetLocale', function () {
    expect(app(SetLocale::class))->toBeInstanceOf(SetPortalLocale::class);
});

test('a guest gets APP_LOCALE instead of English', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'tr')
            ->where('marmara.defaultLocale', 'tr')
            ->where('marmara.branding', null));

    expect(app()->getLocale())->toBe('tr')
        ->and(View::shared('htmlDir'))->toBe('ltr');
});

test('a guest still gets the club default after upstream SetLocale rewrote app.locale', function () {
    $this->get(route('login'))->assertOk();

    // A signed-in request in between (same process) sets app.locale to the user's language.
    $this->actingAs(User::factory()->create(['locale' => Locale::Japanese]))
        ->get(route('app.profile.edit'))
        ->assertOk();

    auth()->logout();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'tr'));

    expect(app()->getLocale())->toBe('tr');
});

test('a signed-in user keeps the language they chose', function () {
    $this->actingAs(User::factory()->create(['locale' => Locale::Japanese]))
        ->get(route('app.profile.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe('ja');
});

test('APP_LOCALE=en, or a locale TryPost does not support, is upstream behaviour', function (string $configured) {
    config()->set('marmara-portal.default_locale', $configured);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', Locale::DEFAULT->value)
            ->where('marmara.defaultLocale', null));

    expect(app()->getLocale())->toBe(Locale::DEFAULT->value);
})->with(['en', 'sv']);

test('branding is shared only when enabled, with the club texts', function () {
    config()->set('marmara-portal.branding.enabled', true);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('marmara.branding.name', 'Marmara Yapay Zeka Kulübü')
            ->where('marmara.branding.tagline', 'Sosyal Medya Paneli')
            ->where('marmara.branding.accent', null)
            ->where('marmara.branding.sourceUrl', null));
});

test('the accent and links are validated, and a logo URL or public file is used', function () {
    config()->set('marmara-portal.branding.enabled', true);
    config()->set('marmara-portal.branding.accent', '#1D4ED8');
    config()->set('marmara-portal.branding.source_url', 'https://github.com/MepCity/trypost');
    config()->set('marmara-portal.branding.logo', 'https://cdn.example.com/club.svg');

    $branding = app(PortalConfig::class)->branding();

    expect($branding['accent'])->toBe('#1d4ed8')
        ->and($branding['sourceUrl'])->toBe('https://github.com/MepCity/trypost')
        ->and($branding['logo'])->toBe('https://cdn.example.com/club.svg')
        ->and($branding['logoDark'])->toBeNull();

    config()->set('marmara-portal.branding.accent', 'red;} body{display:none');
    config()->set('marmara-portal.branding.source_url', 'javascript:alert(1)');
    config()->set('marmara-portal.branding.logo', 'marmara/does-not-exist.svg');

    $branding = app(PortalConfig::class)->branding();

    expect($branding['accent'])->toBeNull()
        ->and($branding['sourceUrl'])->toBeNull()
        ->and($branding['logo'])->toBeNull();
});

test('a logo dropped into public/marmara is picked up without configuration, with a content version', function () {
    config()->set('marmara-portal.branding.enabled', true);

    // A throwaway public/ so the test neither depends on nor touches the real logo.
    $public = sys_get_temp_dir().'/marmara-portal-'.uniqid();
    mkdir($public.'/marmara', 0755, true);
    $original = public_path();
    app()->usePublicPath($public);

    try {
        expect(app(PortalConfig::class)->branding()['logo'])->toBeNull();

        file_put_contents($public.'/marmara/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $first = app(PortalConfig::class)->branding();

        expect($first['logo'])->toStartWith(asset('marmara/logo.svg').'?v=')
            ->and($first['logoDark'])->toBeNull();

        file_put_contents($public.'/marmara/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="2"/>');
        file_put_contents($public.'/marmara/logo-dark.png', 'png');
        $second = app(PortalConfig::class)->branding();

        expect($second['logo'])->not->toBe($first['logo'])
            ->and($second['logoDark'])->toStartWith(asset('marmara/logo-dark.png').'?v=');
    } finally {
        app()->usePublicPath($original);
        array_map('unlink', glob($public.'/marmara/*') ?: []);
        rmdir($public.'/marmara');
        rmdir($public);
    }
});

test('the auth pages share the club branding with it on, and nothing with it off', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Login')
            ->where('marmara.branding', null));

    config()->set('marmara-portal.branding.enabled', true);

    foreach (['login', 'register', 'password.request'] as $route) {
        if (! Route::has($route)) {
            continue;
        }

        $response = $this->get(route($route));

        if ($response->status() !== 200) {
            continue; // e.g. registration closed on this install
        }

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('marmara.branding.name', 'Marmara Yapay Zeka Kulübü')
            ->where('marmara.branding.tagline', 'Sosyal Medya Paneli'));
    }
});
