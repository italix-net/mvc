<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/ViewRenderer.php

declare(strict_types=1);

namespace Italix\Mvc;

/**
 * ViewRenderer — the DI service injected into controllers.
 *
 * Creates a fresh View instance per render so that slots never
 * bleed between requests or nested render calls.
 *
 * Usage in a controller:
 *
 *   $html = $this->view->render('pages/home');
 *   $response->getBody()->write($html);
 *
 * The page file (pages/home.php) receives:
 *   $view — the View instance  (set/begin/end/get/has/render/include/component/form)
 *   $t    — the Translator     (null if not configured)
 *   + any variables passed via the $data array
 *
 * When a theme_dir is set, templates are resolved by checking the theme
 * folder first before falling back to views_path. This lets a theme
 * override any template (including layouts) without touching framework code.
 */
class ViewRenderer
{
    private string $views_path;
    private ?Translator $translator;
    /** @var mixed */
    private $widget_registry;
    private string $theme_dir;

    /** @var array<string, mixed> Available to every template — see share() */
    private array $shared = [];

    public function __construct(
        string      $views_path,
        ?Translator $translator      = null,
        $widget_registry = null,
        string      $theme_dir       = ''
    ) {
        $this->views_path      = rtrim($views_path, '/') . '/';
        $this->translator      = $translator;
        $this->widget_registry = $widget_registry;
        $this->theme_dir       = $theme_dir ? (rtrim($theme_dir, '/') . '/') : '';
    }

    /**
     * Make values available to every template rendered from here on.
     *
     * For the handful of things every page needs and no page should have to ask
     * for — the URL generator is the motivating case: 25 templates would
     * otherwise each require their controller to pass it, and the one that
     * forgot would fail at render time in whichever branch nobody clicked.
     *
     * Data passed to `render()` still wins, so a page can override a shared
     * value for itself. Use sparingly: a shared variable is a global, and its
     * only justification is that the alternative is 25 identical edits.
     *
     * @param array<string, mixed> $data
     */
    public function share(array $data): self
    {
        $this->shared = array_merge($this->shared, $data);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        return $this->shared;
    }

    public function render(string $section, array $data = []): string
    {
        $view = new View($this->views_path, $this->translator, $this->widget_registry, $this->theme_dir, $this->shared);
        $t    = $this->translator;
        extract(array_merge($this->shared, $data));
        ob_start();
        include $this->resolve($section);
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function resolve(string $template): string
    {
        if (substr($template, -4) !== '.php') {
            $template .= '.php';
        }
        if ($this->theme_dir) {
            $theme_file = $this->theme_dir . $template;
            if (file_exists($theme_file)) {
                return $theme_file;
            }
        }
        $file = $this->views_path . $template;
        if (!file_exists($file)) {
            throw new \RuntimeException("ViewRenderer: template not found: {$file}");
        }
        return $file;
    }
}
