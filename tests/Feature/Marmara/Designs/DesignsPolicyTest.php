<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\DesignsServiceProvider;
use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Support\DesignStatus;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    ['workspace' => $this->workspace, 'user' => $this->owner, 'plain_token' => $token] = createApiTestToken();
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    designsConfigure($this->workspace);
    $this->design = designsMake($this->workspace);
});

test('approved and used need the approve right; the other statuses are open to every member', function (string $access, array $allowed) {
    $user = $access === 'owner' ? $this->owner : ($access === 'outsider' ? workspaceOutsider($this->workspace) : workspaceMember($this->workspace, $access));

    $granted = collect(DesignStatus::cases())
        ->filter(fn (DesignStatus $status): bool => Gate::forUser($user)->allows(DesignsServiceProvider::STATUS_ABILITY, [$this->design, $status]))
        ->map->value->values()->all();

    expect($granted)->toBe($allowed);
})->with([
    'admin' => ['owner', ['draft', 'in_review', 'approved', 'used', 'archived']],
    'member who publishes directly' => ['member', ['draft', 'in_review', 'approved', 'used', 'archived']],
    'member whose posts need approval' => ['approval', ['draft', 'in_review', 'archived']],
    'not a member' => ['outsider', []],
]);

test('a member who cannot approve cannot move an approved design back either', function () {
    $this->design->forceFill(['status' => DesignStatus::Approved])->save();
    $member = workspaceMember($this->workspace, 'approval');

    expect(Gate::forUser($member)->allows(DesignsServiceProvider::STATUS_ABILITY, [$this->design, DesignStatus::Draft]))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows(DesignsServiceProvider::STATUS_ABILITY, [$this->design, DesignStatus::Draft]))->toBeTrue();
});

test('the page applies the gate: in review is fine, approved is refused with 403', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)->patch("/designs/{$this->design->id}/status", ['status' => 'in_review'])->assertRedirect();
    expect($this->design->fresh()->status)->toBe(DesignStatus::InReview);

    $this->actingAs($member)->patch("/designs/{$this->design->id}/status", ['status' => 'approved'])->assertForbidden();
    expect($this->design->fresh()->status)->toBe(DesignStatus::InReview);

    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/status", ['status' => 'approved'])->assertRedirect();
    expect($this->design->fresh())->status->toBe(DesignStatus::Approved)->status_changed_at->not->toBeNull()
        ->and(DesignActivity::query()->where('type', 'status_changed')->latest('id')->first()->data)->toBe(['from' => 'in_review', 'to' => 'approved']);
});

test('the api and the page check the same gate', function () {
    Gate::before(fn ($user, string $ability): ?bool => $ability === DesignsServiceProvider::STATUS_ABILITY ? false : null);

    $this->patchJson("/api/designs/{$this->design->id}/status", ['status' => 'in_review'], $this->headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'status_forbidden');
    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/status", ['status' => 'in_review'])->assertForbidden();

    expect($this->design->fresh()->status)->toBe(DesignStatus::Draft);
});

test('an unknown status is a validation error and a design of another workspace is not found', function () {
    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/status", ['status' => 'published'])->assertSessionHasErrors('status');

    ['workspace' => $other] = createApiTestToken();
    $foreign = designsMake($other);

    $this->actingAs($this->owner)->patch("/designs/{$foreign->id}/status", ['status' => 'in_review'])->assertNotFound();
    $this->getJson("/api/designs/{$foreign->id}", $this->headers)->assertNotFound();
});

test('every route answers 404 while the module is off', function () {
    config()->set('marmara-designs.enabled', false);

    $this->actingAs($this->owner)->get('/designs')->assertNotFound();
    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/status", ['status' => 'in_review'])->assertNotFound();
    $this->getJson('/api/designs', $this->headers)->assertNotFound();
});
