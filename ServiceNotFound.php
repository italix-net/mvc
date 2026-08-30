<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * No service is registered under the requested key.
 *
 * Before this existed, asking for an unregistered key produced an "undefined
 * array key" warning and then a TypeError somewhere downstream, in a class
 * that had nothing to do with the mistake. Naming the key at the point of the
 * lookup is the whole improvement.
 */
final class ServiceNotFound extends RuntimeException implements NotFoundExceptionInterface
{
}
