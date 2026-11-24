<?php

declare(strict_types=1);

namespace App\Marmara\Members;

use Illuminate\Support\ServiceProvider;

/**
 * Marmara AI fork module: posts credited to the member who asked for them
 * through WhatsApp (posts.agent_user_id), the Publish page's creator filter
 * and the Insights page's per member counts (app/Marmara/README.md, Members).
 */
class MembersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
