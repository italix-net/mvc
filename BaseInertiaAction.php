<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/BaseInertiaAction.php
//
// Base controller for Inertia.js page controllers.
//
// Inertia protocol:
//   First visit  — returns a full HTML response (the root view) containing a
//                  <div id="app" data-page='...'> that React boots into.
//   Navigation   — the browser sends X-Inertia: true; returns JSON:
//                  {"component":"...", "props":{...}, "url":"...", "version":"..."}
//
// Shared props:
//   InertiaMiddleware populates the '_inertia_shared' request attribute with
//   auth.user, errors (field-level, from session flash), and flash (success/
//   error messages). The inertia() method merges those into every response so
//   no page controller has to pass them manually.
//
// Usage in a controller:
//   return $this->inertia($request, 'Users/Index', ['users' => $rows]);
//
// Redirects:
//   return $this->redirect_to('/users');
//   $this->with_errors(['email' => 'Already taken.']);
//   return $this->redirect_back($request);
//
// PHP 7.4 compatible — this file is in src/Libs/ (shared).

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class BaseInertiaAction extends BaseViewController
{
    // =========================================================================
    // Core Inertia response
    // =========================================================================

    /**
     * Return an Inertia response: JSON for navigations, full HTML for first visits.
     *
     * @param Request $request    The current request (needed for headers + URL)
     * @param string  $component  The React component path, e.g. 'Users/Index'
     * @param array   $props      Page-specific props (merged with shared props)
     */
    protected function inertia(Request $request, string $component, array $props = []): Response
    {
        $shared = $request->getAttribute('_inertia_shared', []);
        if (!is_array($shared)) {
            $shared = [];
        }

        // Page-specific props take priority over shared props on key collisions.
        $merged = array_merge($shared, $props);

        $page = [
            'component' => $component,
            'props'     => $merged,
            'url'       => $this->current_url($request),
            'version'   => $this->inertia_version(),
        ];

        if ($request->hasHeader('X-Inertia')) {
            // Version mismatch: force the client to do a full-page reload.
            $client_ver = $request->getHeaderLine('X-Inertia-Version');
            if ($client_ver !== '' && $client_ver !== $page['version']) {
                $response = Responses::make(409);
                return $response->withHeader('X-Inertia-Location', (string) $request->getUri());
            }

            $body = (string) json_encode($page);
            $response = Responses::make(200);
            $response->getBody()->write($body);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Inertia', 'true')
                ->withHeader('Vary', 'Accept');
        }

        // First visit: render the root HTML shell with the page data embedded.
        return $this->show('root', ['page' => (string) json_encode($page)]);
    }

    // =========================================================================
    // Redirect helpers
    // =========================================================================

    protected function redirect_to(string $url, int $status = 302): Response
    {
        $response = Responses::make($status);
        return $response->withHeader('Location', $url);
    }

    protected function redirect_back(Request $request, int $status = 302): Response
    {
        $referer = $request->getHeaderLine('Referer');
        return $this->redirect_to($referer !== '' ? $referer : '/', $status);
    }

    // =========================================================================
    // Session flash helpers (write — consumed by InertiaMiddleware on next request)
    // =========================================================================

    /**
     * Store field-level validation errors so they appear in the next Inertia
     * response's props['errors'] (consumed by useForm hooks on the client).
     *
     * @param array<string, string> $errors  field_name => human message
     */
    protected function with_errors(array $errors): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_inertia_errors'] = $errors;
    }

    /**
     * Store a one-request flash message (e.g. 'success', 'error').
     * Available in the next request as props['flash'][$key].
     */
    protected function with_flash(string $key, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_inertia_flash'][$key] = $message;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function current_url(Request $request): string
    {
        $uri   = $request->getUri();
        $url   = $uri->getPath();
        $query = $uri->getQuery();
        if ($query !== '') {
            $url .= '?' . $query;
        }
        return $url;
    }

    /**
     * Return a version string for asset cache-busting.
     * Derived from the Vite manifest file so it changes on every production build.
     * Falls back to 'dev' when no manifest is present (local development).
     */
    private function inertia_version(): string
    {
        $candidates = [
            $_SERVER['DOCUMENT_ROOT'] . '/react/build/.vite/manifest.json', // Vite 5
            $_SERVER['DOCUMENT_ROOT'] . '/react/build/manifest.json',        // Vite 4
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return (string) md5_file($path);
            }
        }
        return 'dev';
    }
}
