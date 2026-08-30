<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mvc - the bundled response factory
 *
 * @package Italix\Mvc
 * @external-api Psr\Http\Message\ResponseFactoryInterface
 */

declare(strict_types=1);

namespace Italix\Mvc;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-17 over the PSR-7 implementation this package already depends on.
 *
 * Guzzle 2.x ships `GuzzleHttp\Psr7\HttpFactory`, which does this and more —
 * but only from 2.4, and requiring that would move a floor for one class of
 * four lines. This is the same four lines, with no version to argue about, and
 * it is the only file in the framework that names a PSR-7 implementation.
 *
 * That last sentence is the point. Deleting the `use` at the top of this file
 * and having nothing break is the test of whether the seam works, and it is
 * what {@see Responses::use_factory()} lets an application do without editing
 * anything.
 */
final class GuzzleResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, '1.1', $reasonPhrase === '' ? null : $reasonPhrase);
    }
}
