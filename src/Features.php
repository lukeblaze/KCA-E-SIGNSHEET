<?php
declare(strict_types=1);
namespace Kca;

/** Schema for the features added after the first release (incidents, flags, seal-key use, director profile, search lockouts). */
final class Features
{
    public static function migrate(\PDO $db): void
    {
        // TODO(agent core): create tables here
    }
}
