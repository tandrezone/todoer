<?php

declare(strict_types=1);

namespace App\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ServiceNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
