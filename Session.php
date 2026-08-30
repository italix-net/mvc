<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/Session.php

declare(strict_types=1);

namespace Italix\Mvc;

/**
 * Static session helpers used across the framework.
 *
 * Provides a safe, idempotent way to start the session, plus centralised
 * helpers for CSRF tokens and flash messages. All public methods call
 * ensure_started() internally, so callers never need to worry about
 * invoking session_start() themselves.
 *
 * CSRF tokens
 * -----------
 * One token is generated per session and reused for its lifetime.
 * Embed it in HTML forms via Session::csrf_field() or FormHtml::hidden().
 * For AJAX / fetch() calls, read the token from the <meta name="csrf-token">
 * tag that the admin layout outputs and send it as an X-CSRF-Token header.
 * Validation is done in CsrfMiddleware::process().
 *
 * Flash messages
 * ---------------
 * Flash messages survive exactly one redirect.
 * Store with Session::flash('success', 'Record saved.').
 * Consume with Session::pull_flash() — this reads AND clears them.
 * Display via the admin_flash partial included in the admin layout.
 */
class Session
{
    /**
     * Start the session if it has not been started yet.
     * Safe to call multiple times — checks session_status() first.
     */
    public static function ensure_started(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    /**
     * Return the current CSRF token, creating one if it does not exist yet.
     * The token is a 64-character hex string derived from 32 random bytes.
     */
    public static function csrf_token(): string
    {
        self::ensure_started();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf_token'];
    }

    /**
     * Return a ready-to-embed hidden <input> carrying the CSRF token.
     * Place inside <form> tags for HTML-form submissions.
     */
    public static function csrf_field(): string
    {
        $token = htmlspecialchars(self::csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    /**
     * Validate a submitted CSRF token against the one stored in the session.
     * Uses hash_equals() to prevent timing attacks.
     */
    public static function validate_csrf(string $token): bool
    {
        self::ensure_started();
        $stored = $_SESSION['_csrf_token'] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }

    // =========================================================================
    // Flash messages
    // =========================================================================

    /**
     * Store a flash message that survives exactly one redirect.
     *
     * @param string $type    Severity bucket: 'success', 'error', 'warning', 'info'
     * @param string $message Human-readable message text (not yet HTML-escaped)
     */
    public static function flash(string $type, string $message): void
    {
        self::ensure_started();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Pull all pending flash messages and clear them from the session.
     * Returns an empty array if there are no messages waiting.
     *
     * @return array<int, array{type: string, message: string}>
     */
    public static function pull_flash(): array
    {
        self::ensure_started();
        $messages = isset($_SESSION['_flash']) ? (array) $_SESSION['_flash'] : [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    /**
     * Check whether there are pending flash messages without consuming them.
     */
    public static function has_flash(): bool
    {
        self::ensure_started();
        return !empty($_SESSION['_flash']);
    }
}
