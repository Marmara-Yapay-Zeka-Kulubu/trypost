<?php

declare(strict_types=1);

namespace App\Marmara\Zernio;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Marmara\Hooks\PublishGuardVerdict;
use App\Marmara\Hooks\SocialAccountPublishGuard;
use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Models\Post;

/**
 * A channel mapped to Zernio (TikTok, LinkedIn Page) is checked by Zernio's
 * account health, not by the native token TryPost holds for it: the native
 * token may expire or lose its scopes without affecting the Zernio publish.
 */
class ZernioPublishGuard extends SocialAccountPublishGuard
{
    public function __construct(
        private readonly ZernioConfig $config,
        private readonly ZernioAccountHealth $health,
    ) {}

    public function check(Post $post): PublishGuardVerdict
    {
        $account = $post->socialAccount;
        $zernioAccountId = $this->config->zernioAccountFor($account);

        if ($zernioAccountId === null) {
            return parent::check($post);
        }

        $prefix = ZernioPublishException::prefix($account->platform->value);
        $zernioPlatform = ZernioConfig::zernioPlatform($account->platform);
        $health = $this->health->status($zernioAccountId, $zernioPlatform);
        $zernioAccount = $this->health->account($zernioAccountId, $zernioPlatform);

        // A linkedin-page mapping must point at a company page: a member's
        // personal profile (or an account Zernio does not show as one) is
        // refused before anything is sent.
        if ($account->platform === Platform::LinkedInPage
            && $zernioAccount !== null
            && ZernioAccountHealth::isLinkedInOrganization($zernioAccount) !== true) {
            return PublishGuardVerdict::failed(
                "{$prefix}the Zernio account {$zernioAccountId} is not a LinkedIn company page. Map a page connected to Zernio as an organization.",
                $this->failureContext('zernio_account_not_organization'),
            );
        }

        return match ($health) {
            ZernioAccountHealth::MISSING => PublishGuardVerdict::failed(
                "{$prefix}the Zernio account {$zernioAccountId} is not connected. Reconnect it in Zernio.",
                $this->failureContext('zernio_account_missing'),
            ),
            ZernioAccountHealth::UNHEALTHY => PublishGuardVerdict::failed(
                "{$prefix}the Zernio account {$zernioAccountId} needs to be reconnected in Zernio.",
                $this->failureContext('zernio_account_disconnected'),
            ),
            // Unknown (Zernio unreachable, key refused): the publisher reports it.
            default => PublishGuardVerdict::passed(),
        };
    }

    /**
     * A Zernio-mapped channel's video id comes from Zernio, so its native
     * connection does not matter.
     */
    public function requiresConnectedChannel(Post $post): bool
    {
        return $this->config->zernioAccountFor($post->socialAccount) === null
            && parent::requiresConnectedChannel($post);
    }

    public static function forget(string $zernioAccountId): void
    {
        ZernioAccountHealth::forget($zernioAccountId);
    }

    /**
     * @return array<string, mixed>
     */
    private function failureContext(string $reason): array
    {
        return [
            'category' => ErrorCategory::Permission->value,
            'reason' => $reason,
            'failed_at' => now()->toIso8601String(),
        ];
    }
}
