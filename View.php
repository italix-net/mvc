<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/View.php

declare(strict_types=1);

namespace Italix\Mvc;

/**
 * Per-render view object — created fresh by ViewRenderer for every request.
 *
 * Always available in templates as $view.
 * The translator (if configured) is always available as $t.
 *
 * Folder conventions (under the configured views path):
 *   layouts/     — full-page layout shells  (html/head/body wrapper)
 *   pages/       — page content files       (assembled via begin/end, rendered into a layout)
 *   components/  — isolated reusable blocks (rendered with their own View scope)
 *   partials/    — structural fragments     (nav, footer — share caller scope)
 *   blocks/      — free use
 */
class View
{
    private array   $sections  = [];
    private ?string $capturing = null;
    private string  $views_path;
    private ?Translator $translator;

    // Typed without mixed to stay compatible with PHP 7.4.
    // Pass a \Italix\Forms\WidgetRegistry instance when that library is installed.
    /** @var mixed */
    private $widget_registry;
    private string $theme_dir;

    /**
     * @var array<string, mixed> Variables available to every layout, partial and
     *      component rendered through this View — see ViewRenderer::share().
     *      Structural variables ($view, $t) and per-call $data both win over it.
     */
    private array $shared = [];

    public function __construct(
        string        $views_path,
        ?Translator   $translator      = null,
        $widget_registry = null,
        string        $theme_dir       = '',
        array         $shared          = []
    ) {
        $this->views_path      = rtrim($views_path, '/') . '/';
        $this->translator      = $translator;
        $this->widget_registry = $widget_registry;
        $this->theme_dir       = $theme_dir ? (rtrim($theme_dir, '/') . '/') : '';
        $this->shared          = $shared;
    }

    // -------------------------------------------------------------------------
    // Slot management
    // -------------------------------------------------------------------------

    /**
     * Store a plain string in a named slot (no output buffering needed).
     *
     *   $view->set('title', $t->get('home.title'));
     */
    public function set(string $key, string $value): void
    {
        $this->sections[$key] = $value;
    }

    /**
     * Start capturing output into a named slot.
     *
     *   $view->begin('main');
     */
    public function begin(string $key): void
    {
        if ($this->capturing !== null) {
            throw new \LogicException(
                "View::begin('{$key}') called while already capturing '{$this->capturing}'. Call end() first."
            );
        }
        $this->capturing = $key;
        ob_start();
    }

    /**
     * Stop capturing and store the buffered output.
     *
     * The $key argument is optional — omit it for brevity, or pass it for
     * self-documentation. If provided, it is validated against the active key.
     *
     *   $view->end();         // terse
     *   $view->end('main');   // explicit — throws if begin() key does not match
     */
    public function end(?string $key = null): void
    {
        if ($this->capturing === null) {
            throw new \LogicException('View::end() called without a matching begin().');
        }
        if ($key !== null && $key !== $this->capturing) {
            throw new \LogicException(
                "View::end('{$key}') does not match the active section '{$this->capturing}'."
            );
        }
        $this->sections[$this->capturing] = ob_get_clean();
        $this->capturing = null;
    }

    /**
     * Retrieve a slot value. Returns $default (empty string) if the slot was never set.
     * Used inside skins to output captured content.
     */
    public function get(string $key, string $default = ''): string
    {
        return $this->sections[$key] ?? $default;
    }

    /**
     * Returns true if a slot was set and is non-empty.
     * Useful for conditional blocks in skins.
     */
    public function has(string $key): bool
    {
        return isset($this->sections[$key]) && $this->sections[$key] !== '';
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render a template (typically a skin/layout) with $view and $t in scope.
     * Returns the HTML string — intended for echo.
     *
     * Called at the bottom of a page file:
     *   echo $view->render('layouts/main');
     */
    public function render(string $template): string
    {
        $file = $this->resolve($template);
        extract($this->shared);
        $view = $this;
        $t    = $this->translator;
        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Include a partial directly into the current output stream.
     * The partial shares $view and $t with the caller, plus any extra $data.
     *
     * Called inside skins:
     *   $view->partial('partials/nav');
     */
    public function partial(string $template, array $data = []): void
    {
        $file = $this->resolve($template);
        extract($this->shared);
        $view = $this;
        $t    = $this->translator;
        extract($data);
        include $file;
    }

    /**
     * Render a component in an isolated scope.
     * The component receives its own $data, $t, a fresh $view, and the shared
     * variables. The parent's own variables do NOT leak in.
     *
     * Shared data is not "the parent's variables": it is framework ambient,
     * configured once per request by ViewRenderer::share(). A component that
     * could not see it — a language switcher unable to reach $url — would have
     * to be handed it by every caller, which is the coupling the isolation was
     * meant to prevent.
     *
     *   echo $view->component('components/lang_switcher');
     */
    public function component(string $template, array $data = []): string
    {
        $child = new self($this->views_path, $this->translator, $this->widget_registry, $this->theme_dir, $this->shared);
        $file  = $this->resolve($template);
        extract($this->shared);
        $view  = $child;
        $t     = $this->translator;
        extract($data);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Forms (requires italix/forms library)
    // -------------------------------------------------------------------------

    /**
     * Create a FormHtml instance pre-configured with the WidgetRegistry (if any).
     * Requires the italix/forms library installed at src/Libs/Italix/Forms/.
     *
     *   $form = $view->form($form_meta)->action('/submit')->method('POST');
     *   echo $form->render();
     */
    /** @param mixed $meta @return mixed */
    public function form($meta)
    {
        if (!class_exists('\Italix\Forms\FormHtml')) {
            throw new \RuntimeException(
                'View::form() requires the italix/forms library. ' .
                'Install it manually at src/Libs/Italix/Forms/.'
            );
        }
        return new \Italix\Forms\FormHtml($meta, $this->widget_registry);
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
            throw new \RuntimeException("View template not found: {$file}");
        }
        return $file;
    }
}
