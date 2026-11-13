<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Console;

use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\ZernioClient;
use App\Marmara\Zernio\ZernioConfig;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class ListZernioAccounts extends Command
{
    protected $signature = 'marmara:zernio:accounts {--platform=tiktok : Only this platform (empty for all)}';

    protected $description = 'List the social accounts connected to Zernio, with the id to put in ZERNIO_TIKTOK_ACCOUNT_ID';

    public function handle(ZernioClient $client, ZernioConfig $config): int
    {
        $platform = trim((string) $this->option('platform'));

        try {
            $response = $client->listAccounts(array_filter(['platform' => $platform]));
        } catch (ZernioPublishException $e) {
            $this->error($e->userMessage);

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('Zernio did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Zernio answered HTTP {$response->status()}: ".(data_get($response->json(), 'error') ?? $response->body()));

            return self::FAILURE;
        }

        $mapped = array_flip($config->tiktokAccounts());
        $accounts = collect((array) data_get($response->json(), 'accounts', []))->filter(fn (mixed $account): bool => is_array($account));

        if ($accounts->isEmpty()) {
            $this->warn('No account is connected to Zernio'.($platform !== '' ? " for {$platform}" : '').'. Connect one in the Zernio dashboard.');

            return self::SUCCESS;
        }

        $this->table(
            ['Zernio id', 'Platform', 'Username', 'Name', 'Profile', 'Status', 'Mapped to TryPost'],
            $accounts->map(function (array $account) use ($mapped): array {
                $id = (string) (data_get($account, '_id') ?? data_get($account, 'id') ?? '');
                $profile = data_get($account, 'profileId');

                return [
                    $id,
                    (string) data_get($account, 'platform', ''),
                    (string) data_get($account, 'username', ''),
                    (string) (data_get($account, 'displayName') ?? data_get($account, 'name') ?? ''),
                    (string) (is_array($profile) ? (data_get($profile, '_id') ?? data_get($profile, 'name') ?? '') : ($profile ?? '')),
                    (string) (data_get($account, 'status') ?? (data_get($account, 'isActive') === false ? 'inactive' : '')),
                    (string) ($mapped[$id] ?? ''),
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
