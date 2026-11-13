<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Exceptions;

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use Illuminate\Http\Client\Response;

/**
 * A TikTok publish Zernio refused or reported as failed. It is a
 * SocialPublishException, so PublishToSocialPlatform fails, waits for a limit
 * or reports it exactly as it does a native TikTok refusal.
 *
 * @see https://docs.zernio.com/guides/error-handling
 */
class ZernioPublishException extends SocialPublishException
{
    private const string PREFIX = 'TikTok (via Zernio): ';

    /**
     * Zernio error codes caused by the connected account or its content,
     * which only the user can fix.
     *
     * @var list<string>
     */
    private const array USER_REJECTION_CODES = [
        'ACCOUNT_DISCONNECTED',
        'ACCOUNT_NOT_ENABLED_FOR_POSTING',
        'ACCOUNT_NOTIFICATION_ONLY',
        'PROFILE_OVER_LIMIT',
        'rate_limited',
    ];

    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $code = data_get($response->json(), 'code');
        $code = is_string($code) && $code !== '' ? $code : null;
        $message = self::providerMessage($response, 'error', 'message') ?? "Zernio answered HTTP {$response->status()}.";

        $category = match (true) {
            $response->status() === 429 => ErrorCategory::RateLimit,
            in_array($response->status(), [401, 402, 403], true) => ErrorCategory::Permission,
            $response->status() === 409 => ErrorCategory::ContentPolicy,
            $response->status() === 413, $response->status() === 422 => ErrorCategory::MediaFormat,
            $response->serverError() => ErrorCategory::ServerError,
            default => ErrorCategory::Unknown,
        };

        $exception = new static(
            userMessage: self::PREFIX.$message,
            category: $category,
            platformErrorCode: $code ?? (string) $response->status(),
            rawResponse: $response->body(),
        );

        return $exception
            ->withNetworkReset($response)
            ->asNetworkRejectionIf($code !== null && in_array($code, self::USER_REJECTION_CODES, true));
    }

    /**
     * A TikTok target Zernio marked failed or cancelled.
     *
     * @param  array<string, mixed>  $target
     */
    public static function fromFailedTarget(array $target, ?string $rawResponse = null): static
    {
        $message = self::firstFilled(
            data_get($target, 'errorMessage'),
            data_get($target, 'error.message'),
            data_get($target, 'error'),
            data_get($target, 'platformError.message'),
        ) ?? 'Zernio reported the TikTok publish as '.(data_get($target, 'status') ?: 'failed').'.';

        $errorCategory = strtolower((string) self::firstFilled(data_get($target, 'errorCategory'), ''));

        $category = match (true) {
            str_contains($errorCategory, 'media') => ErrorCategory::MediaFormat,
            str_contains($errorCategory, 'rate'), str_contains($errorCategory, 'limit') => ErrorCategory::RateLimit,
            str_contains($errorCategory, 'auth'), str_contains($errorCategory, 'permission'), str_contains($errorCategory, 'account') => ErrorCategory::Permission,
            str_contains($errorCategory, 'content'), str_contains($errorCategory, 'policy'), str_contains($errorCategory, 'moderation') => ErrorCategory::ContentPolicy,
            default => ErrorCategory::Unknown,
        };

        $exception = new static(
            userMessage: self::PREFIX.$message,
            category: $category,
            platformErrorCode: $errorCategory !== '' ? $errorCategory : null,
            rawResponse: $rawResponse,
        );

        $source = strtolower((string) self::firstFilled(data_get($target, 'errorSource'), ''));

        return $exception->asNetworkRejectionIf($source === 'user' || $source === 'platform');
    }

    public static function misconfigured(string $problem): static
    {
        return new static(
            userMessage: self::PREFIX.$problem,
            category: ErrorCategory::ServerError,
        );
    }

    public static function invalid(string $problem, ErrorCategory $category = ErrorCategory::MediaFormat): static
    {
        return new static(
            userMessage: $problem,
            category: $category,
        );
    }

    public function platform(): string
    {
        return 'tiktok';
    }

    private static function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $message = self::filledMessage($value);

            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }
}
