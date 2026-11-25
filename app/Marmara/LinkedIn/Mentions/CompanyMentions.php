<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Mentions;

/**
 * Marmara AI fork: the company mention marker of a post's text.
 *
 *     @[Display Name](https://www.linkedin.com/company/<vanity>)
 *
 * The link may also be written without the scheme or `www.`
 * (`linkedin.com/company/<vanity>`, with or without a trailing `/`), or as
 * the organization's URN (`urn:li:organization:<id>`). The display name is
 * 1-100 characters without `]` or a line break.
 *
 * LinkedIn turns the marker into a real mention ({@see LinkedInCompanyMentions});
 * every other network, the character counts and the previews get the display
 * name only ({@see self::toPlainText()}).
 */
final class CompanyMentions
{
    public const string PATTERN = '~@\[(?<name>[^\]\r\n]{1,100})\]\((?:urn:li:organization:(?<id>\d+)|(?:https?://)?(?:[a-z]{2,3}\.|www\.)?linkedin\.com/company/(?<vanity>[^\s/?#()\[\]]+)/?)\)~iu';

    /**
     * Every marker of the text, in order.
     *
     * @return list<array{marker: string, offset: int, name: string, vanity: ?string, id: ?string}>
     */
    public static function find(string $text): array
    {
        if (! str_contains($text, '@[') || preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        return array_map(fn (array $match): array => [
            'marker' => $match[0][0],
            'offset' => $match[0][1],
            'name' => trim($match['name'][0]),
            'vanity' => $match['vanity'][0] !== null ? self::vanity($match['vanity'][0]) : null,
            'id' => $match['id'][0],
        ], $matches);
    }

    /**
     * The text with every marker replaced by its display name.
     */
    public static function toPlainText(string $text): string
    {
        if (! str_contains($text, '@[')) {
            return $text;
        }

        return preg_replace_callback(self::PATTERN, fn (array $match): string => trim($match['name']), $text) ?? $text;
    }

    /**
     * A vanity name as LinkedIn's lookup takes it: percent-decoded, lower case.
     */
    public static function vanity(string $vanity): string
    {
        return mb_strtolower(rawurldecode($vanity));
    }
}
