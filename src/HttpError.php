<?php
declare(strict_types=1);
namespace Kca;

final class HttpError extends \RuntimeException
{
    public function __construct(public int $status, string $msg, public array $extra = [])
    {
        parent::__construct($msg);
    }
}
