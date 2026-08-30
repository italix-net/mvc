# Italix Mvc

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

The engine: a PSR-15 pipeline, a service registry, controllers that declare what they need, and a
view layer with block layouts and theme fallback.

Requires `italix/contracts`, `nikic/fast-route`, `guzzlehttp/psr7`, and the PSR HTTP and container
interface packages.

---

## The engine has three doors, and choosing the right one matters

```php
$engine = new Engine($config);

$engine->run();          // read globals, handle, emit — what public/index.php does
$engine->pipeline();     // a PSR-15 handler: global middleware wrapping the router
$engine->boot();         // container + dispatcher built, nothing handled
$engine->registry();     // just the container — does not build the dispatcher
```

`run()` is the web entry point. **`pipeline()` is the interesting one**: it returns the complete
request handler without emitting, so a request can be driven from anywhere — a test, a console
command, a cron job — through the *same* stack a browser goes through. Locale, CSRF, authentication,
routing, controller, view. Nothing is stubbed and nothing is faked.

That is what `Italix\Testing\Client` drives, and what an `ix request` verb drives:

```php
$response = $engine->pipeline()->handle(
    new ServerRequest('GET', '/it/admin/customers/index.html')
);
```

The previous generation of this framework simulated a CLI request by writing `$_GET`, `$_SERVER` and
`HTTP_HOST` by hand until the engine believed it was serving a browser. Everything it faked was a
place where the simulation could diverge from the real thing. There is nothing to fake now: the
request is a real PSR-7 object and the response is a real PSR-7 response. The only step that does not
happen is writing bytes to the socket.

`registry()` deliberately does **not** build the dispatcher. A console command needs the container
and has no route to dispatch; building one would write a route cache file as a side effect of
running `ix version`.

---

## Configuration

`conf.php` returns one array. The keys the engine reads:

| Key | Purpose |
|---|---|
| `di` | `[service key => factory]`, resolved lazily by `ServiceRegistry` |
| `routes` | `fn(FastRoute\RouteCollector $r): void` |
| `middleware.global` | class names, outermost first — runs around routing |
| `middleware` | default per-route middleware, overridable per route |
| `error_handlers` | `[exception class => fn($e, $request, $registry): ResponseInterface]`, matched in order |
| `cache.disabled` | skip the compiled dispatcher entirely |
| `routes.file` | what the cache is invalidated against; defaults to `{base_dir}/src/sites/{host}/routes.php` |
| `cache.dir` | where the compiled dispatcher lives; defaults to `{base_dir}/data/{host}` |
| `base_dir`, `host` | injected by the entry point |

Everything else is passed through as the `config` service.

**`routes.file` and `cache.dir` became configurable in 1.4.0 for a reason worth repeating**: before
that the engine assumed the application kept its routes at `src/sites/{host}/routes.php`, and an
application that did not simply failed `is_file()`. The mtime comparison never fired, the compiled
dispatcher was never invalidated, and a stale route table was served indefinitely — silently, which
is the worst way for a cache to be wrong.

---

## Controllers

Dependencies are **declared, not reflected**:

```php
final class SubjectsAction extends BaseViewController
{
    protected DataManager $dm;
    protected UrlGenerator $url;

    public static function depends_on(string $method = ''): array
    {
        return array_merge(parent::depends_on($method), [
            'dm'  => DataManager::class,
            'url' => UrlGenerator::class,
        ]);
    }
}
```

A declared list can be read without running anything, which is the whole argument against container
autowiring: `grep depends_on` answers "what does this action touch?" and reflection does not.

**Injected properties must be `protected`.** A `private` property of the subclass is invisible from
the base class's scope, and forgetting `array_merge(parent::depends_on(...))` means `$this->view` is
never injected — the constructor raises a named `LogicException` rather than letting you find out at
render time.

`BaseController` gives `input()`, `json()`, `redirect_to()`; `BaseViewController` adds `$this->view`
and `show()`.

---

## Views

Block-and-layout, plain PHP, no compilation step:

```php
$view->begin('main');
    // …
$view->end('main');

echo $view->render('layouts/admin');
```

Every template receives `$view` and `$t` (the translator). `ViewRenderer::share()` adds values to
*every* template, layout, partial and component — for the handful of things no page should have to
ask for:

```php
$view->share(['url' => $url_generator->with(['lang' => $lang])]);
```

Precedence, weakest first: shared → structural (`$view`, `$t`) → per-call `$data`. **Use sparingly**:
a shared variable is a global, and its only justification is that the alternative is editing every
template identically.

Templates resolve against the theme directory first and fall back to `views_path`, so a theme
overrides any template — layouts included — without touching framework code.

### Nothing here escapes anything

`ViewRenderer::render()` returns a `string` and templates encode explicitly with `italix/encode`:

```php
<?php use Italix\Encode\Html as H; ?>
<h1><?= H::e($title) ?></h1>
```

That is a documented position, not an oversight — and `encode-lint` is what keeps it honest.
`Session::csrf_field()` returns markup typed as `string` for the same historical reason; narrowing it
to `Html` is a planned MAJOR (see `VERSIONING.md`).

---

## Middleware

PSR-15 throughout, so middleware written for this engine works in any PSR-15 stack and vice versa.
Global middleware wraps **routing as well**, which is what lets CORS and locale rewriting happen
before a route is matched:

```php
'middleware.global' => [
    LocaleMiddleware::class,   // rewrites /it/chi-siamo → /it/about-us before dispatch
    UrlMiddleware::class,
    CsrfMiddleware::class,
],
```

Per-route middleware is the third element of the handler array in `routes.php`.

`BaseMiddleware` subclasses get the same `depends_on()` injection controllers do.

---

## The service registry

`ServiceRegistry` is PSR-11. Factories are lazy and resolved once:

```php
'di' => [
    DataManager::class => static fn($c) => mysql($c->get('config')),
],
```

Asking for an unregistered key throws `ServiceNotFound` naming the key — before 1.2.0 it produced an
"undefined array key" warning and then a `TypeError` in whatever class received the null, naming
neither the key nor the caller.

Being PSR-11 is what lets `italix/console` resolve a command's dependencies from this registry
without depending on `italix/mvc`.

---

## The PSR-7 implementation is yours to choose

Every response this framework builds comes from a PSR-17 factory:

```php
Responses::use_factory(new \Nyholm\Psr7\Factory\Psr17Factory());
```

Guzzle is the default and stays a dependency, because a framework that refuses to start until you
have picked a PSR-7 implementation has made the first five minutes worse for everybody to please a
minority. But `GuzzleResponseFactory` is now the **only** file in the package that names one — an
application already shipping `nyholm/psr7`, which is a fraction of the size and is sometimes what a
host mandates, no longer carries two implementations to use one framework.

The request is still `GuzzleHttp\Psr7\ServerRequest::fromGlobals()`. PSR-17 has no equivalent —
`ServerRequestFactoryInterface` takes a method and a URI, not the superglobals — so that one entry
point remains concrete.

---

## Known limits

- **Route cache invalidation is mtime-based.** A deployment that preserves timestamps can serve a
  stale dispatcher. A content hash is the fix and is not done.
- **`Session` is static and writes `$_SESSION` directly.** A typed session service is on the list.
- **`engine.php` and `cli.php` are dead.** They are the pre-PSR generation of this library, nothing
  in this repository includes them, and they encode a directory convention the framework no longer
  follows. They are still here in case another project vendors this package and does.
