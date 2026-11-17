<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers;

use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\Models\InboxItem;
use App\Models\SocialAccount;

/**
 * One network (or Zernio) as an inbox source. Implemented only as far as the
 * network's API allows; what it cannot do is answered by capabilities() and
 * missingPermission(), never by a failing request.
 */
interface InboxProvider
{
    /** Stored in `provider` columns: linkedin, instagram, facebook, zernio, x. */
    public function key(): string;

    /** The provider's feature flag (and MARMARA_INBOX_ENABLED). */
    public function enabled(): bool;

    /** Whether this provider can read this channel at all (platform, token, mapping). */
    public function handles(SocialAccount $account): bool;

    /**
     * What the provider does for this channel, per kind:
     * ['comment' => ['see' => bool, 'reply' => bool, 'hide' => bool], 'message' => [...]].
     * A kind left out is not available on this network.
     *
     * @return array<string, array{see: bool, reply: bool, hide: bool}>
     */
    public function capabilities(SocialAccount $account): array;

    /**
     * The permission (scope, App Review item, add-on) the channel still
     * needs to read this kind, or null when nothing is known to be missing.
     */
    public function missingPermission(SocialAccount $account, string $kind): ?string;

    /**
     * The permission still needed to reply to this kind, or null.
     */
    public function missingReplyPermission(SocialAccount $account, string $kind): ?string;

    /**
     * Reads the next page of new items. `$cursor` is what the previous page
     * returned (empty the first time); the sync saves the new one after
     * every page, so a run that stops resumes there.
     *
     * @param  array<string, mixed>  $cursor
     *
     * @throws InboxProviderException
     */
    public function fetch(SocialAccount $account, string $kind, array $cursor): FetchResult;

    /**
     * Sends a reply to the item and answers the network's id of the reply.
     * `$idempotencyKey` is stable per reply row (sent where the API takes one).
     *
     * @throws InboxProviderException
     */
    public function reply(InboxItem $item, string $text, string $idempotencyKey): string;

    /**
     * Hides the comment on the network (the author still sees it).
     *
     * @throws InboxProviderException
     */
    public function hide(InboxItem $item): void;

    /**
     * Why the text cannot be sent as a reply to this item (too long for the
     * network), or null.
     */
    public function replyTextProblem(InboxItem $item, string $text): ?string;
}
