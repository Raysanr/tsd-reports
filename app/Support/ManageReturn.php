<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * TSA / Product / User Management each render in two frames — the main
 * dashboard (layouts.app) and the Hub (layouts.hub-manage) — from ONE view,
 * so the two can never drift apart again (explicit request, 2026-10-20).
 * Each page remembers which frame it was opened from, so a save/delete —
 * including forms app.js builds on the fly — returns to that same frame.
 */
class ManageReturn
{
    public static function remember(string $section, string $routeName): void
    {
        session(["manage_return.{$section}" => $routeName]);
    }

    /** An explicit allowlisted `_redirect_route` wins; else the remembered frame; else $default. */
    public static function target(Request $request, string $section, array $allowed, string $default): string
    {
        foreach ([$request->input('_redirect_route'), session("manage_return.{$section}")] as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return $default;
    }
}
