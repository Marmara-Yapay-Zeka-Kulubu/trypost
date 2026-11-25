<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Publishing;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Marmara\LinkedIn\Mentions\CompanyMentions;
use App\Marmara\LinkedIn\Mentions\LinkedInOrganizationResolver;
use App\Marmara\LinkedIn\Mentions\OrganizationLookup;
use App\Marmara\LinkedIn\NativeLinkedInAccounts;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsAccessToken;
use Throwable;

/**
 * Marmara AI fork: LinkedIn company mentions for a LinkedIn page published
 * through Zernio.
 *
 * Zernio publishes `@[Name](urn:li:...)` written in `content` as a LinkedIn
 * mention, as written (https://docs.zernio.com/platforms/linkedin,
 * https://docs.zernio.com/utilities/linkedin-mentions). Zernio can also
 * resolve `@[Name](https://www.linkedin.com/company/<vanity>)` itself, but it
 * rejects the whole post when it cannot (`linkedin_mentions_invalid`), and a
 * mention must never fail a publish. So the company is resolved here like the
 * native publisher does, and only a resolved URN is sent:
 *
 * - a `urn:li:organization:<id>` marker is sent as is;
 * - a vanity already found by any channel (the resolver's cache) is sent
 *   with LinkedIn's name, without a call;
 * - otherwise it is looked up with a native LinkedIn token of the workspace
 *   (the post's channel, else another connected LinkedIn page, else a
 *   profile): Zernio pages often have none;
 * - no token or not resolved: the display name as plain text (as before).
 */
class ZernioCompanyMentions
{
    public function __construct(private readonly LinkedInOrganizationResolver $resolver) {}

    public function content(string $content, Post $post): string
    {
        $mentions = CompanyMentions::find($content);

        if ($mentions === []) {
            return $content;
        }

        $result = '';
        $position = 0;
        $channel = false;

        foreach ($mentions as $mention) {
            $result .= substr($content, $position, $mention['offset'] - $position);
            $position = $mention['offset'] + strlen($mention['marker']);

            if ($mention['id'] !== null) {
                $result .= $this->markup($mention['name'], $mention['id']);

                continue;
            }

            $vanity = (string) $mention['vanity'];
            $lookup = $this->resolver->cached($vanity);

            if ($lookup === null) {
                // The token is looked for once per post, only when a vanity needs it.
                $channel = $channel === false ? $this->tokenChannel($post) : $channel;
                $lookup = $channel === null
                    ? OrganizationLookup::failed('no native LinkedIn token for the Zernio page')
                    : $this->resolver->resolve($vanity, $channel['token'], $channel['account_id']);

                if (! $lookup->resolved()) {
                    LinkedInOrganizationResolver::warn($vanity, $lookup);
                }
            }

            $result .= $lookup->resolved()
                ? $this->markup($lookup->name ?? $mention['name'], (string) $lookup->id)
                : $mention['name'];
        }

        return $result.substr($content, $position);
    }

    /**
     * Zernio documents no escaping inside `@[...]`: brackets are dropped from
     * the name so the markup stays intact.
     */
    private function markup(string $name, string $id): string
    {
        return '@['.str_replace(['[', ']'], '', $name).'](urn:li:organization:'.$id.')';
    }

    /**
     * @return array{token: string, account_id: string}|null
     */
    private function tokenChannel(Post $post): ?array
    {
        $candidates = SocialAccount::query()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('platform', [Platform::LinkedInPage, Platform::LinkedIn])
            ->where('status', AccountStatus::Connected)
            ->get()
            ->filter(fn (SocialAccount $account): bool => NativeLinkedInAccounts::hasNativeToken($account))
            ->sortBy(fn (SocialAccount $account): int => match (true) {
                $account->id === $post->social_account_id => 0,
                $account->platform === Platform::LinkedInPage => 1,
                default => 2,
            });

        foreach ($candidates as $account) {
            try {
                $token = AnalyticsAccessToken::for($account);
            } catch (Throwable) {
                continue;
            }

            if ($token !== '') {
                return ['token' => $token, 'account_id' => (string) $account->id];
            }
        }

        return null;
    }
}
