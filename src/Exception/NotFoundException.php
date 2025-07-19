<?php

declare(strict_types=1);

namespace Duon\Registry\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * @psalm-api
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface {}
