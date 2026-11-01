<?php
declare(strict_types=1);
namespace Kca\Portal;

use Kca\Config;

final class PortalFactory
{
    public static function make(): PortalAdapter
    {
        return match (Config::get('PORTAL_DRIVER', 'mock')) {
            'http' => new HttpPortal(),
            default => new MockPortal(),
        };
    }
}
