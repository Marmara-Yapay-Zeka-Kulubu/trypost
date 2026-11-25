<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Mentions;

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;

/**
 * Marmara AI fork: TryPost's ContentSanitizer, bound in its place by
 * LinkedInServiceProvider, with company mention markers ({@see CompanyMentions}).
 *
 * Every publisher, preview and character count gets a post's text from the
 * sanitizer, so this is the one place the marker becomes the display name:
 *
 * - `sanitize()`, `displayText()` (character limits) and `plainText()` (the
 *   overall length, mail excerpts): the display name, on every network;
 * - `forPost()` keeps the marker for LinkedIn and LinkedIn pages only, where
 *   `AbstractLinkedInPublisher` turns it into a mention
 *   ({@see LinkedInCompanyMentions}). Thread replies (a LinkedIn comment) go
 *   through `sanitize()` and get the name.
 */
class MentionAwareContentSanitizer extends ContentSanitizer
{
    public function forPost(Post $post): ?string
    {
        if (! self::mentionsOn($post->platform)) {
            return parent::forPost($post);
        }

        return filled($post->content) ? parent::sanitize($post->content, $post->platform) : null;
    }

    public function sanitize(string $content, Platform $platform): string
    {
        return parent::sanitize(CompanyMentions::toPlainText($content), $platform);
    }

    public function plainText(string $content): string
    {
        return parent::plainText(CompanyMentions::toPlainText($content));
    }

    public static function mentionsOn(?Platform $platform): bool
    {
        return in_array($platform, [Platform::LinkedIn, Platform::LinkedInPage], true);
    }
}
