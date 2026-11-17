<?php

declare(strict_types=1);

namespace App\Marmara\Inbox\Connect;

use App\Marmara\Inbox\InboxConfig;

/**
 * Appends the inbox scopes of the controller's platform to its upstream
 * `$scopes` list (config/marmara-inbox.php `scopes`), only while the inbox
 * and the platform's provider are on. Nothing else of the connect changes.
 */
trait AddsInboxScopes
{
    public function __construct()
    {
        $this->scopes = array_values(array_unique([
            ...$this->scopes,
            ...app(InboxConfig::class)->extraScopes($this->platform),
        ]));
    }
}
