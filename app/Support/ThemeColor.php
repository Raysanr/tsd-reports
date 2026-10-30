<?php

namespace App\Support;

/**
 * App-wide theme, chosen in Settings → Appearance (ported from MLC,
 * 2026-10-30). Two colors, matching the two the app is built from:
 *
 *  - Accent: every brand-colored element goes through Tailwind's
 *    yellow/amber scales, and Tailwind v4 emits each utility as
 *    var(--color-…), so overriding those variables on :root re-colors every
 *    page at runtime with no rebuild.
 *  - Sidebar: the near-black menu background (--color-sidebar).
 *
 * Personal (2026-10-30): each signed-in user's own pick, saved on their
 * user row (theme_accent / theme_sidebar); anyone who hasn't picked — and
 * the login page — gets the built-in Gold + Charcoal.
 */
class ThemeColor
{
    public const ACCENT_DEFAULT  = '#CA8A04';
    public const SIDEBAR_DEFAULT = '#18181B';

    /** Key = stored value (the accent's -600 shade). */
    public const ACCENT_PRESETS = [
        '#CA8A04' => 'Seller\'s Hub Gold',
        '#2442B8' => 'Cobalt',
        '#1F6B4A' => 'Green',
        '#187471' => 'Teal',
        '#6639D3' => 'Violet',
        '#CF1F45' => 'Crimson',
        '#C2410C' => 'Orange',
        '#3F4859' => 'Graphite',
    ];

    /** Text color presets — '' = match the accent (the default). */
    public const TEXT_PRESETS = [
        ''        => 'Match accent',
        '#1D4ED8' => 'Blue',
        '#0F766E' => 'Teal',
        '#15803D' => 'Green',
        '#7C3AED' => 'Violet',
        '#BE123C' => 'Rose',
        '#B45309' => 'Amber',
        '#334155' => 'Slate',
    ];

    /** Accent-colored text must clear this against its background (WCAG AA). */
    public const MIN_TEXT_CONTRAST = 4.5;

    public const SIDEBAR_PRESETS = [
        '#18181B' => 'Charcoal',
        '#0F172A' => 'Navy',
        '#1E293B' => 'Slate',
        '#14251C' => 'Forest',
        '#241A12' => 'Espresso',
        '#1F1530' => 'Plum',
        '#FFFBEB' => 'Cream',
        '#FFFFFF' => 'White',
    ];

    /** Mix ratios toward white (lighter steps) / black (darker steps), base = 600. */
    private const STEPS = [
        50 => ['w', .94], 100 => ['w', .88], 200 => ['w', .75], 300 => ['w', .57], 400 => ['w', .36], 500 => ['w', .17],
        600 => ['w', 0], 700 => ['b', .16], 800 => ['b', .29], 900 => ['b', .41], 950 => ['b', .62],
    ];

    public static function accent(): string
    {
        return self::read('theme_accent', self::ACCENT_DEFAULT);
    }

    /** The picked text color, or null when it just follows the accent. */
    public static function text(): ?string
    {
        $hex = self::read('theme_text', '');

        return $hex === '' ? null : $hex;
    }

    /** Text color as applied in light mode: deepened until readable on white. */
    public static function readableTextLight(string $hex): string
    {
        for ($i = 0; $i < 60 && self::contrastWithWhite($hex) < self::MIN_TEXT_CONTRAST; $i++) {
            $hex = self::mix($hex, 0, .05);
        }

        return $hex;
    }

    /** Text color as applied in dark mode: lightened until readable on the
     *  dark page (#0F172A) — a dark violet phone number was unreadable. */
    public static function readableTextDark(string $hex): string
    {
        $bg = self::luminance('#0F172A');
        for ($i = 0; $i < 60 && (self::luminance($hex) + .05) / ($bg + .05) < self::MIN_TEXT_CONTRAST; $i++) {
            $hex = self::mix($hex, 255, .06);
        }

        return $hex;
    }

    /** html.theme-text (app.css/calls.css) recolors accent text — on once
     *  the accent or the text color differs from the built-in gold. */
    public static function textRulesOn(): bool
    {
        return self::text() !== null || self::accent() !== self::ACCENT_DEFAULT;
    }

    public static function sidebar(): string
    {
        return self::read('theme_sidebar', self::SIDEBAR_DEFAULT);
    }

    public static function isValid(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1;
    }

    /** @return array<int,string> shade => #RRGGBB */
    public static function scale(string $hex): array
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
        $out = [];
        foreach (self::STEPS as $shade => [$toward, $amt]) {
            $t = $toward === 'w' ? 255 : 0;
            $mix = fn ($c) => (int) round($c + ($t - $c) * $amt);
            $out[$shade] = sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
        }

        return $out;
    }

    /** Minimum contrast of the accent against white button text. Gold
     *  (the default, ~2.9) just clears it; paler picks get deepened. */
    public const MIN_ACCENT_CONTRAST = 2.8;

    /** The accent as actually applied: the picked color, deepened step by
     *  step toward black until white text on it stays readable. */
    public static function readableAccent(string $hex): string
    {
        for ($i = 0; $i < 40 && self::contrastWithWhite($hex) < self::MIN_ACCENT_CONTRAST; $i++) {
            $hex = self::mix($hex, 0, .05);
        }

        return $hex;
    }

    /** A light sidebar needs dark menu text (html.sidebar-light in app.css/calls.css). */
    public static function sidebarIsLight(): bool
    {
        return self::luminance(self::sidebar()) > .4;
    }

    /** CSS overriding the theme variables, or '' when both are the built-in defaults. */
    public static function css(): string
    {
        $vars = [];

        $accent = self::accent();
        if ($accent !== self::ACCENT_DEFAULT) {
            $s = self::scale(self::readableAccent($accent));
            foreach ($s as $shade => $c) {
                $vars[] = "--color-yellow-{$shade}:{$c}";
                $vars[] = "--color-amber-{$shade}:{$c}";
            }
            $vars[] = "--color-primary:{$s[600]}";
            $vars[] = "--color-primary-dark:{$s[800]}";
            $vars[] = "--color-secondary:{$s[500]}";
            $vars[] = "--color-accent:{$s[700]}";
            $vars[] = "--color-muted:{$s[50]}";
            $vars[] = "--color-border:{$s[200]}";
        }

        if (self::textRulesOn()) {
            $text = self::text() ?? self::accent();
            $vars[] = '--theme-text:' . self::readableTextLight($text);
            $vars[] = '--theme-text-dark:' . self::readableTextDark($text);
        }

        $sidebar = self::sidebar();
        if ($sidebar !== self::SIDEBAR_DEFAULT) {
            $vars[] = "--color-sidebar:{$sidebar}";
        }

        return $vars ? ':root{' . implode(';', $vars) . '}' : '';
    }

    private static function mix(string $hex, int $toward, float $amt): string
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
        $m = fn ($c) => (int) round($c + ($toward - $c) * $amt);

        return sprintf('#%02X%02X%02X', $m($r), $m($g), $m($b));
    }

    private static function luminance(string $hex): float
    {
        $lin = function (int $c) { $c /= 255; return $c <= .03928 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4; };
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return .2126 * $lin($r) + .7152 * $lin($g) + .0722 * $lin($b);
    }

    private static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + .05);
    }

    private static function read(string $column, string $default): string
    {
        try {
            $hex = strtoupper((string) auth()->user()?->{$column});
        } catch (\Throwable) {
            return $default;
        }

        return self::isValid($hex) ? $hex : $default;
    }
}
