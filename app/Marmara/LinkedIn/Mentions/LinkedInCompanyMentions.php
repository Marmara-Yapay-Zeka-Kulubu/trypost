<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Mentions;

use Closure;

/**
 * Marmara AI fork: LinkedIn's `commentary` with real company mentions.
 *
 * Called by `AbstractLinkedInPublisher::basePayload()` (profile and page
 * publishers) with the post text and the publisher's own `little` escaping.
 * The text between markers is escaped exactly as before; each marker
 * ({@see CompanyMentions}) becomes `@[Name](urn:li:organization:<id>)`.
 *
 * - A `urn:li:organization:<id>` marker needs no call; its display name is
 *   the mention text.
 * - A vanity name is resolved with the publishing channel's token
 *   ({@see LinkedInOrganizationResolver}); the mention text is the
 *   organization's `localizedName`, since LinkedIn only links a mention whose
 *   text is the page's exact name (otherwise it shows plain text, without an
 *   error). The display name is used when LinkedIn gives no name.
 * - When the vanity cannot be resolved (no permission, not found, network,
 *   budget), the display name is published as plain, escaped text and a
 *   warning is logged. A mention never fails the publish.
 */
class LinkedInCompanyMentions
{
    /**
     * Every character `little` reserves, escaped inside a mention's name and
     * in an unresolved display name (no hashtags there).
     */
    private const string LITTLE_RESERVED_PATTERN = '/[\\\\|{}@\[\]()<>#*_~]/';

    public function __construct(private readonly LinkedInOrganizationResolver $resolver) {}

    /**
     * @param  Closure(string): string  $escape  the publisher's plain-text escaping
     */
    public function commentary(string $content, string $token, Closure $escape): string
    {
        $mentions = CompanyMentions::find($content);

        if ($mentions === []) {
            return $escape($content);
        }

        $commentary = '';
        $position = 0;
        $resolved = [];

        foreach ($mentions as $mention) {
            $commentary .= $this->escapeSegment(substr($content, $position, $mention['offset'] - $position), $escape);
            $commentary .= $this->mention($mention, $token, $resolved);
            $position = $mention['offset'] + strlen($mention['marker']);
        }

        return $commentary.$this->escapeSegment(substr($content, $position), $escape);
    }

    public static function escapeName(string $name): string
    {
        return preg_replace(self::LITTLE_RESERVED_PATTERN, '\\\\$0', $name) ?? $name;
    }

    /**
     * @param  array{marker: string, offset: int, name: string, vanity: ?string, id: ?string}  $mention
     * @param  array<string, OrganizationLookup>  $resolved  lookups of this post, by vanity
     */
    private function mention(array $mention, string $token, array &$resolved): string
    {
        if ($mention['id'] !== null) {
            return $this->little($mention['name'], $mention['id']);
        }

        $vanity = (string) $mention['vanity'];

        if (! isset($resolved[$vanity])) {
            $resolved[$vanity] = $this->resolver->resolve($vanity, $token);

            if (! $resolved[$vanity]->resolved()) {
                LinkedInOrganizationResolver::warn($vanity, $resolved[$vanity]);
            }
        }

        $lookup = $resolved[$vanity];

        return $lookup->resolved()
            ? $this->little($lookup->name ?? $mention['name'], (string) $lookup->id)
            : self::escapeName($mention['name']);
    }

    private function little(string $name, string $id): string
    {
        return '@['.self::escapeName($name).'](urn:li:organization:'.$id.')';
    }

    /**
     * @param  Closure(string): string  $escape
     */
    private function escapeSegment(string $segment, Closure $escape): string
    {
        return $segment === '' ? '' : $escape($segment);
    }
}
