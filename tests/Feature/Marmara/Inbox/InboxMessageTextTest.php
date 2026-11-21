<?php

declare(strict_types=1);

use App\Marmara\Inbox\Support\InboxMessageText;

/*
 * Message shapes modelled on Zernio's GET /v1/inbox/conversations/{id}/messages
 * (https://docs.zernio.com/messages/get-inbox-conversation-messages) and
 * Graph's Message node (https://developers.facebook.com/docs/graph-api/reference/message).
 */

test('zernio text passes through untouched, emoji included', function () {
    $rendered = InboxMessageText::zernio(['id' => 'mid.1', 'message' => '😍🔥 Harika!', 'attachments' => []]);

    expect($rendered['text'])->toBe('😍🔥 Harika!')
        ->and($rendered['meta']['message_parts'])->toBe(['text']);
});

test('a zernio story mention says so and links the story', function () {
    $rendered = InboxMessageText::zernio([
        'id' => 'mid.2',
        'message' => null,
        'isStoryMention' => true,
        'attachments' => [[
            'id' => 'att1', 'type' => 'share', 'originalType' => 'story_mention',
            'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1&signature=abc',
            'refreshUrl' => 'https://zernio.com/api/v1/inbox/attachments/att1/refresh',
        ]],
    ]);

    expect($rendered['text'])->toBe("📸 Hikâyende bizi etiketledi\nhttps://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1&signature=abc")
        ->and($rendered['meta']['message_parts'])->toBe(['story_mention'])
        ->and($rendered['meta']['story_url'])->toStartWith('https://lookaside.fbsbx.com/')
        ->and($rendered['meta']['source']['isStoryMention'])->toBeTrue()
        ->and($rendered['meta']['source']['attachments'][0]['originalType'])->toBe('story_mention');
});

test('a zernio story reply keeps the emoji answer', function () {
    $rendered = InboxMessageText::zernio([
        'id' => 'mid.3',
        'message' => '😍',
        'storyReply' => ['storyId' => '1790000', 'storyUrl' => 'https://cdn.example/story.mp4'],
        'attachments' => [],
    ]);

    expect($rendered['text'])->toBe("↩️ Hikâyene cevap verdi: 😍\nhttps://cdn.example/story.mp4")
        ->and($rendered['meta']['message_parts'])->toBe(['text', 'story_reply']);
});

test('zernio media and shares become labels, next to the text when there is one', function (array $attachments, ?string $text, string $expected) {
    expect(InboxMessageText::zernio(['id' => 'mid.4', 'message' => $text, 'attachments' => $attachments])['text'])->toBe($expected);
})->with([
    'image' => [[['type' => 'image', 'url' => 'https://cdn.example/a.jpg']], null, '🖼️ Görsel gönderdi'],
    'video' => [[['type' => 'video']], null, '🎥 Video gönderdi'],
    'reel' => [[['type' => 'video', 'originalType' => 'ig_reel']], null, '🎬 Reels paylaştı'],
    'post' => [[['type' => 'share', 'originalType' => 'ig_post']], null, '🔗 Gönderi paylaştı'],
    'audio' => [[['type' => 'audio']], null, '🎤 Ses kaydı gönderdi'],
    'sticker' => [[['type' => 'sticker']], null, '💟 Çıkartma gönderdi'],
    'unsupported' => [[['type' => 'unsupported_type']], null, 'Desteklenmeyen mesaj türü'],
    'text and image' => [[['type' => 'image']], 'Afişimiz 👇', "Afişimiz 👇\n🖼️ Görsel gönderdi"],
]);

test('a zernio message meta withholds, or zernio did not keep, is not empty', function () {
    expect(InboxMessageText::zernio(['message' => null, 'attachments' => [], 'noRenderableContent' => true])['text'])->toBe('Desteklenmeyen mesaj türü');

    $notRetained = InboxMessageText::zernio(['message' => null, 'attachments' => [], 'contentNotRetained' => true]);
    expect($notRetained['text'])->toBe(InboxMessageText::NOT_RETAINED)
        ->and($notRetained['meta']['content_not_retained'])->toBeTrue();
});

test('the zernio webhook shape (text instead of message) renders the same way', function () {
    $rendered = InboxMessageText::zernio([
        'id' => '665f', 'platformMessageId' => 'mid.5', 'text' => null,
        'attachments' => [['type' => 'share', 'originalType' => 'story_mention', 'url' => 'https://cdn.example/s.jpg']],
    ]);

    expect($rendered['text'])->toBe("📸 Hikâyende bizi etiketledi\nhttps://cdn.example/s.jpg");
});

test('graph story mentions, story replies, media, shares and unsupported messages are readable', function (array $message, string $expected) {
    expect(InboxMessageText::graph(['id' => 'mid.g', 'created_time' => '2026-11-17T18:03:00+0000', ...$message])['text'])->toBe($expected);
})->with([
    'story mention' => [['story' => ['mention' => ['link' => 'https://cdn.example/story.jpg', 'id' => '1790']]], "📸 Hikâyende bizi etiketledi\nhttps://cdn.example/story.jpg"],
    'story reply' => [['message' => '😍', 'story' => ['reply_to' => ['link' => 'https://cdn.example/ours.jpg', 'id' => '1791']]], "↩️ Hikâyene cevap verdi: 😍\nhttps://cdn.example/ours.jpg"],
    'image' => [['attachments' => ['data' => [['id' => 'a1', 'image_data' => ['url' => 'https://cdn.example/i.jpg', 'width' => 10, 'height' => 10]]]]], '🖼️ Görsel gönderdi'],
    'sticker' => [['attachments' => ['data' => [['id' => 'a2', 'image_data' => ['url' => 'https://cdn.example/s.png', 'render_as_sticker' => true]]]]], '💟 Çıkartma gönderdi'],
    'video' => [['attachments' => ['data' => [['id' => 'a3', 'video_data' => ['url' => 'https://cdn.example/v.mp4']]]]], '🎥 Video gönderdi'],
    'shared reel' => [['shares' => ['data' => [['type' => 'ig_reel', 'url' => 'https://www.instagram.com/reel/x']]]], '🎬 Reels paylaştı'],
    'shared post' => [['shares' => ['data' => [['link' => 'https://www.instagram.com/p/x']]]], '🔗 Gönderi paylaştı'],
    'unsupported' => [['is_unsupported' => true], 'Desteklenmeyen mesaj türü'],
    'text and image' => [['message' => 'Bakın 🙌', 'attachments' => ['data' => [['image_data' => ['url' => 'https://cdn.example/i.jpg']]]]], "Bakın 🙌\n🖼️ Görsel gönderdi"],
    'plain emoji' => [['message' => '❤️‍🔥'], '❤️‍🔥'],
]);

test('a reaction reads as one line with its emoji', function () {
    expect(InboxMessageText::reaction('😂'))->toBe('😂 Mesaja tepki verdi')
        ->and(InboxMessageText::reaction(null))->toBe('❤️ Mesaja tepki verdi')
        ->and(InboxMessageText::reactionId('mid.1', '😂'))->toBe('reaction:mid.1:😂');
});
