<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use App\Marmara\Designs\Models\DesignActivity;
use App\Marmara\Designs\Models\DesignComment;
use App\Marmara\Designs\Models\DesignTag;
use App\Marmara\Designs\Models\DesignVote;

beforeEach(function () {
    ['workspace' => $this->workspace, 'user' => $this->owner, 'plain_token' => $token] = createApiTestToken();
    $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    designsConfigure($this->workspace);
    $this->design = designsMake($this->workspace);
    $this->member = workspaceMember($this->workspace, 'approval');
});

test('members comment, edit and delete their own comments, with author and time', function () {
    $this->travelTo(now()->setTime(14, 0));

    $this->actingAs($this->member)->post("/designs/{$this->design->id}/comments", ['body' => '  Renkler çok güzel  '])->assertRedirect();

    $comment = DesignComment::query()->sole();
    expect($comment->body)->toBe('Renkler çok güzel')
        ->and($comment->user_id)->toBe($this->member->id)
        ->and($comment->created_at->format('H:i'))->toBe('14:00')
        ->and($this->design->fresh())->comments_count->toBe(1)->last_commented_at->not->toBeNull();

    $this->travel(5)->minutes();
    $this->actingAs($this->member)->patch("/designs/{$this->design->id}/comments/{$comment->id}", ['body' => 'Renkler harika'])->assertRedirect();
    expect($comment->fresh())->body->toBe('Renkler harika')->edited_at->not->toBeNull();

    $this->actingAs($this->member)->delete("/designs/{$this->design->id}/comments/{$comment->id}")->assertRedirect();
    expect(DesignComment::query()->count())->toBe(0)
        ->and($this->design->fresh())->comments_count->toBe(0)->last_commented_at->toBeNull()
        ->and(DesignActivity::query()->where('design_id', $this->design->id)->orderBy('id')->pluck('type')->all())->toBe(['commented', 'comment_edited', 'comment_deleted']);
});

test('nobody edits or deletes another member\'s comment, not even an admin', function () {
    $this->actingAs($this->member)->post("/designs/{$this->design->id}/comments", ['body' => 'Benim yorumum']);
    $comment = DesignComment::query()->sole();

    $this->actingAs($this->owner)->patch("/designs/{$this->design->id}/comments/{$comment->id}", ['body' => 'değişti'])->assertForbidden();
    $this->actingAs($this->owner)->delete("/designs/{$this->design->id}/comments/{$comment->id}")->assertForbidden();

    expect($comment->fresh()->body)->toBe('Benim yorumum');
});

test('one vote per member: voting twice counts once, and a vote can be taken back', function () {
    $this->actingAs($this->member)->post("/designs/{$this->design->id}/vote")->assertRedirect();
    $this->actingAs($this->member)->post("/designs/{$this->design->id}/vote")->assertRedirect();
    $this->actingAs($this->owner)->post("/designs/{$this->design->id}/vote")->assertRedirect();

    expect(DesignVote::query()->count())->toBe(2)
        ->and($this->design->fresh()->votes_count)->toBe(2);

    $this->actingAs($this->member)->delete("/designs/{$this->design->id}/vote")->assertRedirect();
    $this->actingAs($this->member)->delete("/designs/{$this->design->id}/vote")->assertRedirect();

    expect($this->design->fresh()->votes_count)->toBe(1)
        ->and(DesignActivity::query()->where('type', 'voted')->count())->toBe(2)
        ->and(DesignActivity::query()->where('type', 'unvoted')->count())->toBe(1);
});

test('free tags are normalized and replaced as a set', function () {
    $this->actingAs($this->member)->put("/designs/{$this->design->id}/tags", ['tags' => ['#Afiş', 'instagram', ' afiş ', 'Story']])->assertRedirect();
    expect(DesignTag::query()->where('design_id', $this->design->id)->orderBy('tag')->pluck('tag')->all())->toBe(['afiş', 'instagram', 'story']);

    $this->actingAs($this->member)->put("/designs/{$this->design->id}/tags", ['tags' => ['story']])->assertRedirect();
    expect(DesignTag::query()->where('design_id', $this->design->id)->pluck('tag')->all())->toBe(['story'])
        ->and(DesignActivity::query()->where('type', 'tags_changed')->latest('id')->first()->data)->toBe(['added' => [], 'removed' => ['afiş', 'instagram']]);
});

test('separate files linked as versions form one family, ordered by time, and only the newest shows in the gallery', function () {
    $v1 = designsMake($this->workspace, ['title' => 'Afiş taslak', 'drive_created_at' => now()->subDays(5)]);
    $v2 = designsMake($this->workspace, ['title' => 'Afiş düzeltme', 'drive_created_at' => now()->subDays(3)]);
    $v3 = designsMake($this->workspace, ['title' => 'Afiş son', 'drive_created_at' => now()->subDay()]);

    $this->actingAs($this->member)->post("/designs/{$v2->id}/versions", ['previous_id' => $v1->id])->assertRedirect();
    $this->actingAs($this->member)->post("/designs/{$v3->id}/versions", ['previous_id' => $v2->id])->assertRedirect();

    expect([$v1->fresh()->family_id, $v2->fresh()->family_id, $v3->fresh()->family_id])->toBe([$v1->id, $v1->id, $v1->id])
        ->and([$v1->fresh()->family_latest, $v2->fresh()->family_latest, $v3->fresh()->family_latest])->toBe([false, false, true])
        ->and($v3->fresh()->family()->pluck('id')->all())->toBe([$v1->id, $v2->id, $v3->id]);

    $this->actingAs($this->member)->post("/designs/{$v3->id}/versions", ['previous_id' => $v1->id])->assertSessionHasErrors('previous_id');

    $this->actingAs($this->member)->delete("/designs/{$v3->id}/versions")->assertRedirect();
    expect($v3->fresh())->family_id->toBeNull()->family_latest->toBeTrue()
        ->and($v2->fresh()->family_latest)->toBeTrue();
});

test('the api reads designs and adds comments as the key\'s user', function () {
    $this->getJson('/api/designs?status=draft', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->design->id)
        ->assertJsonPath('data.0.uploader.name', 'Yusuf Kulüp')
        ->assertJsonMissingPath('data.0.owner_email')
        ->assertJsonPath('has_more', false);

    $this->postJson("/api/designs/{$this->design->id}/comments", ['body' => 'Echo\'dan selam'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('author.id', $this->owner->id);

    $response = $this->getJson("/api/designs/{$this->design->id}", $this->headers)->assertOk()->assertJsonPath('comments_list.0.body', 'Echo\'dan selam');
    expect($response->json('thumbnail_url'))->toContain('signature=');
});

test('the gallery filters and sorts', function () {
    $poster = designsMake($this->workspace, ['title' => 'Bahar afişi', 'folder_path' => 'Bahar Şenliği/Afişler', 'kind' => 'image', 'votes_count' => 3, 'drive_created_at' => now()->subDays(2)]);
    $video = designsMake($this->workspace, ['title' => 'Tanıtım', 'folder_path' => 'Bahar Şenliği', 'kind' => 'video', 'uploader_name' => 'Elif', 'drive_created_at' => now()->subDays(20)]);
    DesignTag::query()->create(['design_id' => $video->id, 'tag' => 'reels']);

    $ids = fn (string $query): array => collect($this->getJson("/api/designs?{$query}", $this->headers)->json('data'))->pluck('id')->all();

    expect($ids('folder=Bahar%20%C5%9Eenli%C4%9Fi'))->toEqualCanonicalizing([$poster->id, $video->id])
        ->and($ids('folder=Bahar%20%C5%9Eenli%C4%9Fi/Afi%C5%9Fler'))->toBe([$poster->id])
        ->and($ids('folder=/'))->toBe([$this->design->id])
        ->and($ids('kind=video'))->toBe([$video->id])
        ->and($ids('uploader=Elif'))->toBe([$video->id])
        ->and($ids('tag=reels'))->toBe([$video->id])
        ->and($ids('q=bahar'))->toBe([$poster->id])
        ->and($ids('from='.now()->subDays(25)->toDateString().'&to='.now()->subDays(15)->toDateString()))->toBe([$video->id])
        ->and($ids('sort=votes')[0])->toBe($poster->id)
        ->and($ids('sort=oldest')[0])->toBe($video->id)
        ->and($ids('sort=title'))->toBe([$this->design->id, $poster->id, $video->id]);
});
