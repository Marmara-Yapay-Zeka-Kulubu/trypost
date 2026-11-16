<?php

declare(strict_types=1);

namespace App\Marmara\LinkedIn\Analytics\Collectors;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Marmara\LinkedIn\Analytics\Concerns\FallsBackForOtherAccounts;
use App\Marmara\LinkedIn\LinkedInApi;
use App\Marmara\LinkedIn\LinkedInConfig;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractPublicationHistoryCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * A LinkedIn page's post history (discovery and backfill) from the Posts API
 * author finder, newest created first; the cursor is the finder's `start`.
 * Posts published from TryPost are linked by TryPost itself, so discovery
 * only looks for posts made on LinkedIn directly and reads the list at most
 * every MARMARA_LINKEDIN_DISCOVERY_INTERVAL_HOURS. Sponsored-only (dark)
 * posts and posts that are not published are left out.
 */
class NativeLinkedInPagePublicationCollector extends AbstractPublicationHistoryCollector
{
    use FallsBackForOtherAccounts;

    /** A cutoff this recent is a discovery run, not a backfill. */
    private const int DISCOVERY_CUTOFF_DAYS = 3;

    public function __construct(
        private readonly NativeLinkedInAccounts $accounts,
        private readonly LinkedInApi $api,
        private readonly LinkedInConfig $config,
    ) {}

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $reason = $this->accounts->reason($account);

        if ($reason !== null) {
            /** @var AbstractPublicationHistoryCollector $previous */
            $previous = $this->previous($reason);

            return $previous->page($account, $cursor, $cutoff);
        }

        if ($account->missingScope('r_organization_social') !== null) {
            return PublicationPage::scopeMissing();
        }

        $start = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : 0;
        $discovery = $start === 0 && $cutoff->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->subDays(self::DISCOVERY_CUTOFF_DAYS));
        $throttleKey = "marmara-linkedin:discovery:{$account->id}";

        if ($discovery && Cache::has($throttleKey)) {
            return new PublicationPage([], null, true);
        }

        $count = $this->config->pageSize();
        $result = $this->api->posts($account, $start, $count);
        $publications = [];
        $reachedCutoff = false;

        foreach ($result['elements'] as $post) {
            $createdAt = $this->milliseconds(data_get($post, 'createdAt'));

            if ($createdAt !== null && $createdAt->lessThan($cutoff)) {
                $reachedCutoff = true;

                break;
            }

            $publication = $this->publication($post);

            if ($publication !== null && $publication->publishedAt->greaterThanOrEqualTo($cutoff)) {
                $publications[] = $publication;
            }
        }

        if ($discovery && $this->config->discoveryIntervalHours() > 0) {
            Cache::put($throttleKey, true, now()->addHours($this->config->discoveryIntervalHours()));
        }

        $hasNext = $result['hasMore'] && ! $reachedCutoff;

        return new PublicationPage(
            $publications,
            $hasNext ? (string) ($start + count($result['elements'])) : null,
            ! $hasNext,
            providerRowCount: count($result['elements']),
        );
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function publication(array $post): ?DiscoveredPublication
    {
        $urn = data_get($post, 'id');
        $publishedAt = $this->milliseconds(data_get($post, 'publishedAt')) ?? $this->milliseconds(data_get($post, 'createdAt'));
        $lifecycle = data_get($post, 'lifecycleState');

        if (! is_string($urn) || LinkedInApi::urnKind($urn) === null || $publishedAt === null
            || ($lifecycle !== null && $lifecycle !== 'PUBLISHED')
            || data_get($post, 'adContext.isDsc') === true
            || data_get($post, 'distribution.feedDistribution') === 'NONE') {
            return null;
        }

        [$contentType, $providerType] = $this->contentType($post);
        $commentary = data_get($post, 'commentary');

        return new DiscoveredPublication(
            providerPostId: $urn,
            publishedAt: $publishedAt,
            contentType: $contentType,
            providerContentType: $providerType,
            permalink: "https://www.linkedin.com/feed/update/{$urn}",
            excerpt: is_string($commentary) && $commentary !== '' ? mb_substr($commentary, 0, 2000) : null,
            providerMetadata: array_filter([
                'linkedin_source' => 'native',
                'linkedin_reshare' => data_get($post, 'reshareContext') !== null ? true : null,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array{PublicationContentType, string}
     */
    private function contentType(array $post): array
    {
        $mediaId = (string) data_get($post, 'content.media.id', '');

        return match (true) {
            str_starts_with($mediaId, 'urn:li:video:') => [PublicationContentType::Video, 'video'],
            str_starts_with($mediaId, 'urn:li:image:') => [PublicationContentType::Image, 'image'],
            str_starts_with($mediaId, 'urn:li:document:') => [PublicationContentType::Unknown, 'document'],
            data_get($post, 'content.multiImage') !== null => [PublicationContentType::Carousel, 'multiImage'],
            data_get($post, 'content.article') !== null => [PublicationContentType::Link, 'article'],
            data_get($post, 'content.poll') !== null => [PublicationContentType::Poll, 'poll'],
            data_get($post, 'reshareContext') !== null => [PublicationContentType::Unknown, 'reshare'],
            blank(data_get($post, 'content')) => [PublicationContentType::Text, 'text'],
            default => [PublicationContentType::Unknown, 'other'],
        };
    }

    private function milliseconds(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) && (int) $value > 0
            ? CarbonImmutable::createFromTimestampMsUTC((int) $value)
            : null;
    }
}
