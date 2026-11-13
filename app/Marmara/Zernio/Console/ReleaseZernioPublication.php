<?php

declare(strict_types=1);

namespace App\Marmara\Zernio\Console;

use App\Marmara\Zernio\Models\ZernioPublication;
use Illuminate\Console\Command;

class ReleaseZernioPublication extends Command
{
    protected $signature = 'marmara:zernio:release {post : The TryPost post id}';

    protected $description = 'After checking Zernio by hand, let the next publish of a post create a new Zernio post';

    public function handle(): int
    {
        $attempt = ZernioPublication::query()->where('post_id', (string) $this->argument('post'))->first();

        if ($attempt === null) {
            $this->warn('This post has no Zernio attempt.');

            return self::SUCCESS;
        }

        $this->line('Zernio post: '.($attempt->zernio_post_id ?? 'none').", status: {$attempt->last_status}, reference: {$attempt->reference()}");

        if (! $this->confirm('Only continue if Zernio has no live post for it. Allow a new Zernio post on the next retry?')) {
            return self::SUCCESS;
        }

        $attempt->update([
            'last_status' => ZernioPublication::STATUS_FAILED,
            'last_error' => 'Released by an operator after a manual check.',
        ]);

        $this->info('Released. Retry the post (posts:retry or from the app) to publish it again.');

        return self::SUCCESS;
    }
}
