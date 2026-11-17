<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Providers\Meta;

use App\Enums\SocialAccount\Platform;
use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\InboxConfig;
use App\Marmara\Inbox\Support\InboxAccessToken;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Graph API calls for the inbox, on the host and version TryPost's own Meta
 * integration uses for the channel (config trypost.platforms.*.graph_api):
 * graph.instagram.com for "Instagram" (Instagram Login), graph.facebook.com
 * for "Instagram (Facebook)" and Facebook Pages (Page token).
 *
 * Graph errors become InboxProviderException categories; tokens never reach
 * a message.
 *
 * @see https://developers.facebook.com/docs/graph-api/guides/error-handling
 */
class MetaGraph
{
    /** Graph codes that mean "slow down" (app, user, page and BUC limits). */
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80004, 80005, 80006, 80008, 80014];

    /** Subcodes of a send outside the messaging window. */
    private const array WINDOW_SUBCODES = [2018278, 2018108, 2534022];

    public function __construct(private readonly InboxConfig $config) {}

    public static function base(Platform $platform): string
    {
        $key = match ($platform) {
            Platform::Instagram => 'trypost.platforms.instagram.graph_api',
            Platform::InstagramFacebook => 'trypost.platforms.instagram-facebook.graph_api',
            default => 'trypost.platforms.facebook.graph_api',
        };
        $default = $platform === Platform::Instagram ? 'https://graph.instagram.com/v25.0' : 'https://graph.facebook.com/v25.0';

        return rtrim((string) config($key, $default), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(SocialAccount $account, string $path, array $query, string $operation): array
    {
        return $this->send($account, 'get', $path, $query, $operation);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(SocialAccount $account, string $path, array $body, string $operation): array
    {
        return $this->send($account, 'post', $path, $body, $operation);
    }

    /**
     * Reads `next` paging URLs too (they carry the token, so they stay inside this class).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(SocialAccount $account, string $method, string $path, array $payload, string $operation): array
    {
        $token = InboxAccessToken::for($account);
        $url = str_starts_with($path, 'https://') ? $path : self::base($account->platform).'/'.ltrim($path, '/');

        try {
            $request = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20);
            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->asJson()->post($url, $payload);
        } catch (ConnectionException) {
            throw new InboxProviderException('transient', "Meta {$operation}: no answer in time");
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        throw $this->failure($response, $operation);
    }

    private function failure(Response $response, string $operation): InboxProviderException
    {
        $code = (int) data_get($response->json(), 'error.code', 0);
        $subcode = (int) data_get($response->json(), 'error.error_subcode', 0);
        $message = data_get($response->json(), 'error.message');
        $detail = is_string($message) ? ' ('.mb_substr(trim($message), 0, 200).')' : '';

        if ($response->status() === 429 || in_array($code, self::RATE_LIMIT_CODES, true)) {
            return InboxProviderException::rateLimited("Meta {$operation}: rate limited{$detail}", $this->retryAt($response));
        }

        if (in_array($subcode, self::WINDOW_SUBCODES, true) || $code === 551 || ($code === 10 && str_contains(strtolower((string) $message), 'window'))) {
            return InboxProviderException::windowClosed("Meta {$operation}: outside the messaging window{$detail}");
        }

        return match (true) {
            $code === 190 || $response->status() === 401 => new InboxProviderException('authentication', "Meta refused the token: Refresh connection on the channel card{$detail}"),
            $code === 10 || ($code >= 200 && $code <= 299) || $response->status() === 403 => new InboxProviderException('needs_permission', "needs permission for {$operation} (Meta: check the scope and App Review access){$detail}"),
            $response->serverError() || in_array($code, [1, 2], true) => new InboxProviderException('transient', "Meta {$operation} failed with HTTP {$response->status()}"),
            default => new InboxProviderException('error', "Meta {$operation} failed with HTTP {$response->status()}{$detail}"),
        };
    }

    /**
     * When the limit lifts: X-Business-Use-Case-Usage's
     * `estimated_time_to_regain_access` (minutes), else the module's back-off.
     */
    private function retryAt(Response $response): CarbonImmutable
    {
        $minutes = 0;
        $usage = json_decode($response->header('X-Business-Use-Case-Usage') ?: '[]', true);

        foreach (is_array($usage) ? $usage : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                $minutes = max($minutes, (int) data_get($entry, 'estimated_time_to_regain_access', 0));
            }
        }

        return CarbonImmutable::now('UTC')->addMinutes($minutes > 0 ? $minutes : $this->config->metaBackoffMinutes());
    }
}
