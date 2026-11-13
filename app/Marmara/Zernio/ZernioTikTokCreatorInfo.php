<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Enums\TikTok\PrivacyLevel;
use App\Exceptions\PlatformUnavailableException;
use App\Models\SocialAccount;
use App\Services\Social\TikTokCreatorInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The composer's TikTok options (privacy levels, comment/duet/stitch
 * switches, max video length) for a Zernio-mapped channel come from the TikTok
 * app that publishes it: Zernio's, not TryPost's unaudited one, which only
 * offers SELF_ONLY. Other channels keep the native creator_info.
 *
 * @see https://docs.zernio.com/accounts/get-tiktok-creator-info
 */
class ZernioTikTokCreatorInfo extends TikTokCreatorInfo
{
    private const int CACHE_MINUTES = 5;

    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioClient $client,
    ) {
        parent::__construct();
    }

    public function fetch(SocialAccount $account): array
    {
        $zernioAccountId = $this->config->zernioAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::fetch($account);
        }

        try {
            return $this->fromZernio($account, $zernioAccountId);
        } catch (PlatformUnavailableException) {
            return $this->fallback($account);
        }
    }

    public function fetchOrFail(SocialAccount $account): array
    {
        $zernioAccountId = $this->config->zernioAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::fetchOrFail($account);
        }

        return $this->fromZernio($account, $zernioAccountId);
    }

    /**
     * A daily posting limit is reported like the native one (429). Zernio not
     * answering gives the fallback: the channel publishes through Zernio, and
     * Zernio publishes videos public to everyone.
     *
     * @return array{
     *     creator_nickname: ?string,
     *     creator_username: ?string,
     *     creator_avatar_url: ?string,
     *     privacy_level_options: array<int, string>,
     *     comment_disabled: bool,
     *     duet_disabled: bool,
     *     stitch_disabled: bool,
     *     max_video_post_duration_sec: ?int,
     * }
     *
     * @throws PlatformUnavailableException
     */
    private function fromZernio(SocialAccount $account, string $zernioAccountId): array
    {
        $cacheKey = "marmara-zernio:tiktok-creator-info:{$account->id}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->creatorInfo($zernioAccountId, 'video');
        } catch (Throwable $e) {
            Log::warning('Marmara Zernio: creator info unavailable', ['social_account_id' => $account->id, 'exception' => $e->getMessage()]);

            return $this->fallback($account);
        }

        if ($response->status() === 429) {
            throw new PlatformUnavailableException(
                __('posts.form.tiktok.creator_info_limit_reached'),
                Response::HTTP_TOO_MANY_REQUESTS,
                ['platform_error_code' => 'zernio_daily_limit'],
            );
        }

        if ($response->failed()) {
            Log::warning('Marmara Zernio: creator info refused', ['social_account_id' => $account->id, 'status' => $response->status()]);

            return $this->fallback($account);
        }

        $data = (array) $response->json();
        $interactions = (array) data_get($data, 'postingLimits.interactionSettings', []);
        $canPostMore = data_get($data, 'creator.canPostMore') !== false;
        $maxDuration = data_get($data, 'postingLimits.maxVideoDurationSec');

        $creatorInfo = [
            'creator_nickname' => $this->stringOrNull(data_get($data, 'creator.nickname')) ?? $this->stringOrNull($account->display_name ?? null),
            'creator_username' => $this->stringOrNull($account->username),
            'creator_avatar_url' => $this->stringOrNull(data_get($data, 'creator.avatarUrl')),
            'privacy_level_options' => $canPostMore ? array_values(array_intersect(
                collect((array) data_get($data, 'privacyLevels', []))
                    ->map(fn (mixed $level): string => (string) (is_array($level) ? data_get($level, 'value') : $level))
                    ->all(),
                PrivacyLevel::values(),
            )) : [],
            'comment_disabled' => data_get($interactions, 'allow_comment.enabled') === false,
            'duet_disabled' => data_get($interactions, 'allow_duet.enabled') === false,
            'stitch_disabled' => data_get($interactions, 'allow_stitch.enabled') === false,
            'max_video_post_duration_sec' => is_numeric($maxDuration) ? (int) $maxDuration : null,
        ];

        Cache::put($cacheKey, $creatorInfo, now()->addMinutes(self::CACHE_MINUTES));

        return $creatorInfo;
    }

    /**
     * Used when Zernio cannot be asked: not cached, so the next open asks
     * again.
     *
     * @return array{
     *     creator_nickname: ?string,
     *     creator_username: ?string,
     *     creator_avatar_url: null,
     *     privacy_level_options: array<int, string>,
     *     comment_disabled: bool,
     *     duet_disabled: bool,
     *     stitch_disabled: bool,
     *     max_video_post_duration_sec: null,
     * }
     */
    private function fallback(SocialAccount $account): array
    {
        return [
            'creator_nickname' => $this->stringOrNull($account->display_name ?? null),
            'creator_username' => $this->stringOrNull($account->username),
            'creator_avatar_url' => null,
            'privacy_level_options' => [PrivacyLevel::PublicToEveryone->value],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
