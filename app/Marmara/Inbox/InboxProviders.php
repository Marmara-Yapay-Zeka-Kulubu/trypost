<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

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
 * twice.
 */
class InboxProviders
{
    public function __construct(
        private readonly LinkedInInboxProvider $linkedIn,
        private readonly InstagramInboxProvider $instagram,
        private readonly FacebookInboxProvider $facebook,
        private readonly XInboxProvider $x,
        private readonly ZernioInboxProvider $zernio,
    ) {}

    public function for(SocialAccount $account): ?InboxProvider
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
