<?php

declare(strict_types=1);

test('the privacy policy is public and answers while the designs module is off', function (bool $enabled) {
    config()->set('marmara-designs.enabled', $enabled);

    $this->get('/gizlilik')
        ->assertOk()
        ->assertSee('Gizlilik Politikası')
        ->assertSee('drive.file')
        ->assertSee('myaccount.google.com/permissions')
        ->assertSee('hamasetyasir@gmail.com')
        ->assertSee('Privacy Policy (English)');

    expect(route('marmara.privacy'))->toEndWith('/gizlilik');
})->with([true, false]);
