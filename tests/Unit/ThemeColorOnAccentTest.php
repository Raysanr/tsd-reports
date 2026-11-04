<?php

namespace Tests\Unit;

use App\Support\ThemeColor;
use PHPUnit\Framework\TestCase;

/** Header text must stay readable on every accent (2026-11-04). */
class ThemeColorOnAccentTest extends TestCase
{
    public function test_it_picks_the_readable_text_for_each_background(): void
    {
        $this->assertSame('#201C12', ThemeColor::onAccent('#CA8A04')); // gold
        $this->assertSame('#F1F5F9', ThemeColor::onAccent('#6639D3')); // violet
        $this->assertSame('#F1F5F9', ThemeColor::onAccent('#2442B8')); // cobalt
    }

    public function test_the_css_gold_defaults_match_the_calculation(): void
    {
        $scale = ThemeColor::scale(ThemeColor::ACCENT_DEFAULT);

        foreach (['app', 'calls'] as $file) {
            $css = file_get_contents(dirname(__DIR__, 2) . "/resources/css/{$file}.css");
            $this->assertStringContainsString('.dark body { background: #020617; color: #E2E8F0; }', $css);
            foreach ([500, 600, 700, 800] as $shade) {
                $this->assertStringContainsString("--on-accent-{$shade}: " . ThemeColor::onAccent($scale[$shade]), $css);
            }
        }
    }
}
