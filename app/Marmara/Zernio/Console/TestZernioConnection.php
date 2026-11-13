<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Console;

use App\Marmara\Zernio\Exceptions\ZernioPublishException;
use App\Marmara\Zernio\ZernioClient;
use App\Marmara\Zernio\ZernioConfig;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class TestZernioConnection extends Command
{
    protected $signature = 'marmara:zernio:test';

    protected $description = 'Check the Zernio configuration and that the API key is accepted';

    public function handle(ZernioClient $client, ZernioConfig $config): int
    {
        $this->line('Zernio publishing: '.($config->enabled() ? 'enabled' : 'disabled (ZERNIO_ENABLED=false, TikTok publishes natively)'));
        $this->line('Base URL: '.$config->baseUrl());
        $this->line('Media mode: '.$config->mediaMode());

        foreach ($config->problems() as $problem) {
            $this->warn($problem);
        }

        try {
            $response = $client->listAccounts(['platform' => 'tiktok']);
        } catch (ZernioPublishException $e) {
            $this->error($e->userMessage);

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('Zernio did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->error('Zernio refused the API key ('.(data_get($response->json(), 'code') ?? $response->status()).').');

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Zernio answered HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $this->info('The API key works.');

        $zernioIds = collect((array) data_get($response->json(), 'accounts', []))
            ->map(fn (mixed $account): string => (string) (data_get($account, '_id') ?? data_get($account, 'id') ?? ''))
            ->filter()
            ->all();

        $missing = false;

        foreach ($config->tiktokAccounts() as $trypostAccount => $zernioAccountId) {
            if (in_array($zernioAccountId, $zernioIds, true)) {
                $this->info("TryPost {$trypostAccount} -> Zernio TikTok account {$zernioAccountId}: connected.");
            } else {
                $this->error("TryPost {$trypostAccount} -> Zernio account {$zernioAccountId}: not among this key's TikTok accounts.");
                $missing = true;
            }
        }

        return $missing || ($config->enabled() && $config->problems() !== []) ? self::FAILURE : self::SUCCESS;
    }
}
