<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/BaseViewController.php

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Convenience base for controllers that render HTML views.
 *
 * Provides:
 *   - $view (ViewRenderer) injected automatically
 *   - show(string $path, array $data): Response  — render + wrap in 200 HTML response
 *
 * If your controller needs additional services, merge with the parent in depends_on():
 *
 *   public static function depends_on(string $method = ''): array
 *   {
 *       return array_merge(parent::depends_on($method), [
 *           'dm' => DataManager::class,
 *       ]);
 *   }
 *
 * Forgetting the merge means $view will not be injected and the first call to
 * $this->view will throw an "Uninitialized property" error.
 */
abstract class BaseViewController extends BaseController
{
    protected ViewRenderer $view;

    public static function depends_on(string $method = ''): array
    {
        return ['view' => ViewRenderer::class];
    }

    protected function show(string $path, array $data = []): Response
    {
        $html     = $this->view->render($path, $data);
        $response = Responses::make(200);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
