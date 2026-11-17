<?php

declare(strict_types=1);

namespace App\Marmara\Inbox;

use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\Models\InboxSyncState;
use App\Marmara\Inbox\Providers\InboxProvider;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * One sync of one channel, provider and kind. The cursor is saved after
 * every page, so a run stopped by a rate limit, a budget or a crash resumes
 * where it was. A missing permission is a status ("needs permission X"),
 * not an error; a rate limit blocks the state until the network's reset.
 */
class InboxSync
{
    public function __construct(
        private readonly InboxConfig $config,
        private readonly InboxItemStore $store,
    ) {}

    public function state(SocialAccount $account, InboxProvider $provider, string $kind): InboxSyncState
    {
        return InboxSyncState::query()->firstOrCreate(
            ['social_account_id' => $account->id, 'provider' => $provider->key(), 'kind' => $kind],
            ['status' => InboxSyncState::STATUS_PENDING],
        );
    }

    /**
     * Whether the state is due: not blocked, and its interval has passed.
     */
    public function due(InboxSyncState $state): bool
    {
        if ($state->isBlocked()) {
            return false;
        }

        return $state->last_attempt_at === null
            || $state->last_attempt_at->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes($this->config->intervalMinutes($state->provider))->addSeconds(30));
    }

    /**
     * @return int how many new items were stored
     */
    public function run(SocialAccount $account, InboxProvider $provider, string $kind): int
    {
        $state = $this->state($account, $provider, $kind);

        if ($state->isBlocked()) {
            return 0;
        }

        $state->forceFill(['last_attempt_at' => now()])->save();

        if (! array_key_exists($kind, $provider->capabilities($account))) {
            $this->mark($state, InboxSyncState::STATUS_UNSUPPORTED, "{$provider->key()} does not offer {$kind}s for this channel");

            return 0;
        }

        $missing = $provider->missingPermission($account, $kind);

        if ($missing !== null) {
            $this->mark($state, InboxSyncState::STATUS_NEEDS_PERMISSION, "needs permission {$missing}");

            return 0;
        }

        $stored = 0;

        try {
            for ($page = 0; $page < $this->config->maxPagesPerSync(); $page++) {
                $result = $provider->fetch($account, $kind, $state->cursor ?? []);

                foreach ($result->items as $data) {
                    if ($this->store->ingest($account, $provider, $data) !== null) {
                        $stored++;
                    }
                }

                $state->forceFill(['cursor' => $result->cursor])->save();

                if (! $result->hasMore) {
                    break;
                }
            }
        } catch (InboxProviderException $exception) {
            $this->fail($state, $exception);

            return $stored;
        }

        $state->forceFill([
            'status' => InboxSyncState::STATUS_OK,
            'status_detail' => null,
            'blocked_until' => null,
            'last_synced_at' => now(),
        ])->save();

        return $stored;
    }

    private function fail(InboxSyncState $state, InboxProviderException $exception): void
    {
        match ($exception->category) {
            'rate_limited' => $state->forceFill([
                'status' => InboxSyncState::STATUS_RATE_LIMITED,
                'status_detail' => $exception->getMessage(),
                'blocked_until' => $exception->retryAt ?? CarbonImmutable::now()->addMinutes(30),
            ])->save(),
            'needs_permission' => $this->mark($state, InboxSyncState::STATUS_NEEDS_PERMISSION, $exception->getMessage()),
            'authentication' => $this->mark($state, InboxSyncState::STATUS_AUTHENTICATION, $exception->getMessage()),
            'unsupported' => $this->mark($state, InboxSyncState::STATUS_UNSUPPORTED, $exception->getMessage()),
            default => $this->mark($state, InboxSyncState::STATUS_ERROR, $exception->getMessage()),
        };

        if (! in_array($exception->category, ['rate_limited', 'needs_permission', 'unsupported'], true)) {
            Log::warning('Marmara inbox sync failed', [
                'social_account_id' => $state->social_account_id,
                'provider' => $state->provider,
                'kind' => $state->kind,
                'category' => $exception->category,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function mark(InboxSyncState $state, string $status, string $detail): void
    {
        $state->forceFill(['status' => $status, 'status_detail' => mb_substr($detail, 0, 1000)])->save();
    }
}
