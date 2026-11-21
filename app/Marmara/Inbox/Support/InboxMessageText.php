<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Support;

/**
 * Turns a DM that is not (only) text into readable text for the inbox and
 * Echo: a story mention, a story reply, a reaction, media, a shared post, a
 * message Meta withholds. The text is in Turkish, the club's language, since
 * it is stored once on the item and sent as is in `inbox.item_received`.
 * The person's own text (emoji included) passes through untouched; a message
 * with text and an attachment shows both. The source fields go to `meta`.
 *
 * Zernio: GET /v1/inbox/conversations/{id}/messages and the message.received
 * webhook (https://docs.zernio.com/messages/get-inbox-conversation-messages,
 * https://docs.zernio.com/webhooks/inbox/message-received).
 * Graph: the Message node (https://developers.facebook.com/docs/graph-api/reference/message).
 */
final class InboxMessageText
{
    public const string STORY_MENTION = '📸 Hikâyende bizi etiketledi';

    public const string STORY_REPLY = '↩️ Hikâyene cevap verdi';

    public const string REACTION = 'Mesaja tepki verdi';

    public const string UNSUPPORTED = 'Desteklenmeyen mesaj türü';

    public const string NOT_RETAINED = 'Mesajın içeriği Zernio\'da saklanmıyor';

    /** Zernio attachment types and Meta share types, by what they show. */
    private const array LABELS = [
        'image' => '🖼️ Görsel gönderdi',
        'gif' => '🖼️ GIF gönderdi',
        'video' => '🎥 Video gönderdi',
        'reel' => '🎬 Reels paylaştı',
        'audio' => '🎤 Ses kaydı gönderdi',
        'file' => '📎 Dosya gönderdi',
        'sticker' => '💟 Çıkartma gönderdi',
        'post' => '🔗 Gönderi paylaştı',
        'story' => '📸 Hikâye paylaştı',
        'template' => '🧩 Şablon mesaj gönderdi',
    ];

    /** Meta's original types (Zernio `originalType`, Graph share `type`). */
    private const array ORIGINAL_TYPES = [
        'ig_reel' => 'reel', 'reel' => 'reel', 'clip' => 'reel',
        'ig_post' => 'post', 'post' => 'post', 'share' => 'post', 'media_share' => 'post',
        'ig_story' => 'story',
    ];

    /**
     * A Zernio message, from the list API (`message`) or the webhook (`text`).
     *
     * @param  array<string, mixed>  $message
     * @return array{text: string|null, meta: array<string, mixed>}
     */
    public static function zernio(array $message): array
    {
        $text = data_get($message, 'message') ?? data_get($message, 'text');
        $text = is_string($text) && $text !== '' ? $text : null;
        $attachments = array_values(array_filter((array) data_get($message, 'attachments', []), 'is_array'));
        $storyReply = data_get($message, 'storyReply');
        $notRetained = (bool) data_get($message, 'contentNotRetained', false);
        $labels = [];
        $parts = [];
        $mention = (bool) data_get($message, 'isStoryMention', false);
        $mentionUrl = null;

        foreach ($attachments as $attachment) {
            $type = (string) data_get($attachment, 'type', '');
            $original = (string) data_get($attachment, 'originalType', '');

            if ($original === 'story_mention') {
                $mention = true;
                $mentionUrl ??= self::url(data_get($attachment, 'url'));

                continue;
            }

            $kind = match (true) {
                $type === 'unsupported_type' || $original === 'unsupported_type' => 'unsupported',
                isset(self::ORIGINAL_TYPES[$original]) => self::ORIGINAL_TYPES[$original],
                $type === 'share' => 'post',
                isset(self::LABELS[$type]) => $type,
                default => 'unsupported',
            };
            $parts[] = $original !== '' ? "{$type}:{$original}" : $type;
            $labels[] = $kind === 'unsupported' ? self::UNSUPPORTED : self::LABELS[$kind];
        }

        if ($mention) {
            array_unshift($parts, 'story_mention');
            array_unshift($labels, self::STORY_MENTION.self::link($mentionUrl));
        }

        $isStoryReply = is_array($storyReply);

        if ($isStoryReply) {
            array_unshift($parts, 'story_reply');
        }

        if ((bool) data_get($message, 'noRenderableContent', false)) {
            $parts[] = 'no_renderable_content';
            $labels[] = self::UNSUPPORTED;
        }

        $rendered = self::compose(
            $text,
            array_values(array_unique($labels)),
            $isStoryReply ? self::url(data_get($storyReply, 'storyUrl')) : null,
            $isStoryReply,
            $notRetained,
        );

        return [
            'text' => $rendered,
            'meta' => array_filter([
                'message_parts' => $text !== null ? ['text', ...$parts] : $parts,
                'content_not_retained' => $notRetained,
                'story_url' => $mentionUrl ?? ($isStoryReply ? self::url(data_get($storyReply, 'storyUrl')) : null),
                'source' => array_filter([
                    'attachments' => $attachments,
                    'isStoryMention' => data_get($message, 'isStoryMention'),
                    'storyReply' => $storyReply,
                    'noRenderableContent' => data_get($message, 'noRenderableContent'),
                    'contentNotRetained' => data_get($message, 'contentNotRetained'),
                    'isDeleted' => data_get($message, 'isDeleted'),
                ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false),
            ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false),
        ];
    }

    /**
     * A Graph message (Instagram or Messenger) read with
     * attachments, story, shares, sticker and is_unsupported.
     *
     * @param  array<string, mixed>  $message
     * @return array{text: string|null, meta: array<string, mixed>}
     */
    public static function graph(array $message): array
    {
        $text = data_get($message, 'message');
        $text = is_string($text) && $text !== '' ? $text : null;
        $labels = [];
        $parts = [];
        $mentionUrl = self::url(data_get($message, 'story.mention.link'));
        $mention = is_array(data_get($message, 'story.mention'));
        $replyTo = data_get($message, 'story.reply_to');
        $isStoryReply = is_array($replyTo);

        foreach ((array) data_get($message, 'attachments.data', []) as $attachment) {
            $type = (string) data_get($attachment, 'type', '');

            if ($type === 'story_mention') {
                $mention = true;
                $mentionUrl ??= self::url(data_get($attachment, 'payload.url') ?? data_get($attachment, 'url'));

                continue;
            }

            $kind = match (true) {
                isset(self::ORIGINAL_TYPES[$type]) => self::ORIGINAL_TYPES[$type],
                isset(self::LABELS[$type]) => $type,
                (bool) data_get($attachment, 'image_data.render_as_sticker', false) => 'sticker',
                is_string(data_get($attachment, 'image_data.animated_gif_url')) => 'gif',
                is_array(data_get($attachment, 'image_data')) => 'image',
                is_array(data_get($attachment, 'video_data')) => 'video',
                str_starts_with((string) data_get($attachment, 'mime_type', ''), 'audio/') => 'audio',
                is_string(data_get($attachment, 'file_url')) => 'file',
                default => 'unsupported',
            };
            $parts[] = $kind;
            $labels[] = $kind === 'unsupported' ? self::UNSUPPORTED : self::LABELS[$kind];
        }

        foreach ((array) data_get($message, 'shares.data', []) as $share) {
            $kind = self::ORIGINAL_TYPES[(string) data_get($share, 'type', '')] ?? 'post';
            $parts[] = 'share:'.$kind;
            $labels[] = self::LABELS[$kind];
        }

        if (is_string(data_get($message, 'sticker'))) {
            $parts[] = 'sticker';
            $labels[] = self::LABELS['sticker'];
        }

        if ($mention) {
            array_unshift($parts, 'story_mention');
            array_unshift($labels, self::STORY_MENTION.self::link($mentionUrl));
        }

        if ($isStoryReply) {
            array_unshift($parts, 'story_reply');
        }

        if ((bool) data_get($message, 'is_unsupported', false)) {
            $parts[] = 'is_unsupported';
            $labels[] = self::UNSUPPORTED;
        }

        $replyUrl = $isStoryReply ? self::url(data_get($replyTo, 'link')) : null;

        return [
            'text' => self::compose($text, array_values(array_unique($labels)), $replyUrl, $isStoryReply, false),
            'meta' => array_filter([
                'message_parts' => $text !== null ? ['text', ...$parts] : $parts,
                'story_url' => $mentionUrl ?? $replyUrl,
                'source' => array_filter([
                    'attachments' => data_get($message, 'attachments'),
                    'story' => data_get($message, 'story'),
                    'shares' => data_get($message, 'shares'),
                    'sticker' => data_get($message, 'sticker'),
                    'is_unsupported' => data_get($message, 'is_unsupported'),
                ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false),
            ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false),
        ];
    }

    /**
     * "😍 Mesaja tepki verdi" (❤️ when the network sends no emoji).
     */
    public static function reaction(?string $emoji): string
    {
        $emoji = is_string($emoji) && trim($emoji) !== '' ? trim($emoji) : '❤️';

        return "{$emoji} ".self::REACTION;
    }

    /**
     * The id of the item a reaction makes: one per reacted message and
     * emoji, the same on the list API, the webhook and Graph.
     */
    public static function reactionId(string $messageId, ?string $emoji): string
    {
        return 'reaction:'.$messageId.':'.(is_string($emoji) && trim($emoji) !== '' ? trim($emoji) : '❤️');
    }

    /**
     * @param  list<string>  $labels
     */
    private static function compose(?string $text, array $labels, ?string $storyUrl, bool $isStoryReply, bool $notRetained): ?string
    {
        if ($isStoryReply) {
            $head = self::STORY_REPLY.($text !== null ? ': '.$text : '').self::link($storyUrl);

            return implode("\n", [$head, ...$labels]);
        }

        $lines = array_values(array_filter([$text, ...$labels], fn (?string $line): bool => $line !== null && $line !== ''));

        if ($lines !== []) {
            return implode("\n", $lines);
        }

        return $notRetained ? self::NOT_RETAINED : self::UNSUPPORTED;
    }

    private static function link(?string $url): string
    {
        return $url !== null ? "\n".$url : '';
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://#i', $value) === 1 ? $value : null;
    }
}
