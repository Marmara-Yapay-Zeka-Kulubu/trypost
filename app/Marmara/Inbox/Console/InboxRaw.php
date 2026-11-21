<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Console;

use App\Marmara\Inbox\Exceptions\InboxProviderException;
use App\Marmara\Inbox\InboxProviders;
use App\Marmara\Inbox\Models\InboxItem;
use App\Marmara\Inbox\Providers\Meta\MetaInboxProvider;
use App\Marmara\Inbox\Providers\ZernioInboxProvider;
use Illuminate\Console\Command;

/**
 * Prints what is stored for an inbox item (text and meta) and the raw JSON
 * its provider answers for it now (Zernio or Graph), to see the real shape
 * of a message that rendered oddly. Read only: no write, no reply, no mark
 * read. Tokens, secrets and signed query strings are redacted.
 */
class InboxRaw extends Command
{
    protected $signature = 'marmara:inbox:raw {item : The inbox item id} {--stored : Only the stored item, no provider call}';

    protected $description = 'Show the stored meta and the provider\'s raw JSON of an inbox item (Marmara AI fork)';

    private const string REDACTED = '[redacted]';

    public function handle(InboxProviders $providers): int
    {
        $item = InboxItem::query()->with('socialAccount')->find((string) $this->argument('item'));

        if ($item === null) {
            $this->error('No inbox item with this id.');

            return self::FAILURE;
        }

        $this->line('Stored item:');
        $this->line($this->json([
            'id' => $item->id,
            'provider' => $item->provider,
            'platform' => $item->platform,
            'kind' => $item->kind,
            'external_id' => $item->external_id,
            'thread_id' => $item->thread_id,
            'received_at' => $item->received_at->toIso8601String(),
            'text' => $item->text,
            'meta' => $item->meta,
        ]));

        if ((bool) $this->option('stored')) {
            return self::SUCCESS;
        }

        $provider = $providers->byKey($item->provider);

        if (! $provider instanceof ZernioInboxProvider && ! $provider instanceof MetaInboxProvider) {
            $this->warn("The {$item->provider} provider has no raw fetch.");

            return self::SUCCESS;
        }

        try {
            $raw = $provider->rawMessage($item);
        } catch (InboxProviderException $exception) {
            $this->error('The provider call failed: '.self::redactString($exception->getMessage()));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Provider ({$item->provider}): {$raw['request']}");
        $this->line('Matching entry:');
        $this->line($this->json($raw['match']));
        $this->newLine();
        $this->line('Full answer:');
        $this->line($this->json($raw['response']));

        return self::SUCCESS;
    }

    /**
     * Redacts every value under a key that names a credential, and the
     * credential query parameters of every URL (signed CDN links keep their
     * path, so the media type stays visible).
     */
    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(token|secret|password|signature|api_?key|authorization)/i', $key) === 1) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $child) {
                $redacted[$childKey] = self::redact($child, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        return is_string($value) ? self::redactString($value) : $value;
    }

    public static function redactString(string $value): string
    {
        $value = (string) preg_replace('/([?&](?:access_token|token|oh|oe|_nc_sig|signature|sig|key|apikey|api_key)=)[^&\s"]+/i', '$1'.self::REDACTED, $value);

        return (string) preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1'.self::REDACTED, $value);
    }

    private function json(mixed $value): string
    {
        return (string) json_encode(self::redact($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
