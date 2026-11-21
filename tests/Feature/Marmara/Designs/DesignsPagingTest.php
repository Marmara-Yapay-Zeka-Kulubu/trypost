<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\Mcp\ListDesignsTool;
use App\Marmara\Designs\Models\Design;
use App\Mcp\Servers\TryPostServer;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    ['workspace' => $this->workspace, 'user' => $this->owner, 'plain_token' => $token] = createApiTestToken();
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    designsConfigure($this->workspace);

    // 45 designs with distinct creation times, so "newest" is a strict order.
    $this->ids = collect(range(1, 45))
        ->map(fn (int $index): string => designsMake($this->workspace, ['drive_created_at' => now()->subMinutes($index)])->id)
        ->all();
});

test('the api pages through every design exactly once', function () {
    $seen = [];
    $hasMore = [];

    foreach ([1, 2, 3] as $page) {
        $response = $this->getJson("/api/designs?limit=20&page={$page}", $this->headers)->assertOk();
        $seen = [...$seen, ...collect($response->json('data'))->pluck('id')->all()];
        $hasMore[] = $response->json('has_more');
    }

    expect($seen)->toBe($this->ids)
        ->and($hasMore)->toBe([true, true, false]);
});

test('the mcp list tool pages through every design exactly once', function () {
    $seen = [];
    $hasMore = [];

    foreach ([1, 2, 3] as $page) {
        TryPostServer::actingAs($this->owner)
            ->tool(ListDesignsTool::class, ['limit' => 20, 'page' => $page])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$seen, &$hasMore): void {
                $content = $json->toArray();
                $seen = [...$seen, ...array_column($content['designs'], 'id')];
                $hasMore[] = $content['has_more'];
                $json->etc();
            });
    }

    expect($seen)->toBe($this->ids)
        ->and($hasMore)->toBe([true, true, false])
        ->and(Design::query()->count())->toBe(45);
});
