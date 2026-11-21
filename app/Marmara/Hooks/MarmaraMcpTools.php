<?php

declare(strict_types=1);

namespace App\Marmara\Hooks;

/**
 * The MCP tools the fork's modules add to upstream's server, registered from
 * each module provider's register() (MarmaraTryPostServer reads them).
 */
final class MarmaraMcpTools
{
    /** @var list<class-string> */
    private static array $tools = [];

    /**
     * @param  list<class-string>  $tools
     */
    public static function register(array $tools): void
    {
        self::$tools = array_values(array_unique([...self::$tools, ...$tools]));
    }

    /**
     * @return list<class-string>
     */
    public static function all(): array
    {
        return self::$tools;
    }
}
