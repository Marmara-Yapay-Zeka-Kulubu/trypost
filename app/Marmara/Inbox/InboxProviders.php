<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Providers\InboxProvider;
use App\Marmara\Inbox\Providers\LinkedInInboxProvider;
use App\Marmara\Inbox\Providers\Meta\FacebookInboxProvider;
use App\Marmara\Inbox\Providers\Meta\InstagramInboxProvider;
use App\Marmara\Inbox\Providers\XInboxProvider;
use App\Marmara\Inbox\Providers\ZernioInboxProvider;
use App\Models\SocialAccount;

/**
 * Picks the one provider that reads a channel:
 *
 * 1. a channel listed in MARMARA_INBOX_ZERNIO_ACCOUNTS goes to Zernio;
 * 2. else the native provider of its network, when that provider is on;
 * 3. else Zernio, when the channel is mapped to a Zernio account
 *    (ZERNIO_ACCOUNTS: TikTok, a LinkedIn page published through Zernio).
 *
 * A provider whose flag is off is never picked, so a channel is never read
 * twice; of two channels of one Instagram account only one reads
 * (readThroughTwin).
 */
class InboxProviders
{
    private const array INSTAGRAM = [Platform::Instagram, Platform::InstagramFacebook];

    public function __construct(
        private readonly LinkedInInboxProvider $linkedIn,
        private readonly InstagramInboxProvider $instagram,
        private readonly FacebookInboxProvider $facebook,
        private readonly XInboxProvider $x,
        private readonly ZernioInboxProvider $zernio,
    ) {}

    public function for(SocialAccount $account): ?InboxProvider
    {
        $provider = $this->resolve($account);

        if ($provider === null || $this->readThroughTwin($account) !== null) {
            return null;
        }

        return $provider;
    }

    /**
     * The other channel of the workspace that reads this one's Instagram
     * account instead: TryPost connects the same Instagram account twice
     * (Instagram login and Facebook login), and both would otherwise read
     * the same DMs and comments, each through its own provider (for example
     * the native one and Zernio). One of them reads: the channel mapped in
     * MARMARA_INBOX_ZERNIO_ACCOUNTS, else the one connected first.
     */
    public function readThroughTwin(SocialAccount $account): ?SocialAccount
    {
        if (! in_array($account->platform, self::INSTAGRAM, true) || blank($account->username)) {
            return null;
        }

        $username = strtolower(ltrim((string) $account->username, '@'));
        $readers = SocialAccount::query()
            ->where('workspace_id', $account->workspace_id)
            ->whereIn('platform', self::INSTAGRAM)
            ->get()
            ->filter(fn (SocialAccount $candidate): bool => strtolower(ltrim((string) $candidate->username, '@')) === $username
                && $this->resolve($candidate) !== null)
            ->sortBy(fn (SocialAccount $candidate): array => [
                $this->zernio->enabled() && $this->zernio->explicitlyMapped($candidate) ? 0 : 1,
                $candidate->created_at?->getTimestamp() ?? PHP_INT_MAX,
                (string) $candidate->id,
            ]);

        $reader = $readers->first();

        return $reader !== null && $reader->id !== $account->id ? $reader : null;
    }

    private function resolve(SocialAccount $account): ?InboxProvider
    {
        if ($this->zernio->enabled() && $this->zernio->explicitlyMapped($account)) {
            return $this->zernio;
        }

        foreach ([$this->linkedIn, $this->instagram, $this->facebook, $this->x] as $native) {
            if ($native->enabled() && $native->handles($account)) {
                return $native;
            }
        }

        return $this->zernio->enabled() && $this->zernio->handles($account) ? $this->zernio : null;
    }

    public function byKey(string $key): ?InboxProvider
    {
        foreach ($this->all() as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return list<InboxProvider>
     */
    public function all(): array
    {
        return [$this->linkedIn, $this->instagram, $this->facebook, $this->x, $this->zernio];
    }
}
