<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            // RequestMemo (perf, 2026-10-15) — Teams::config() alone read
            // the same keys ~40x per Expected Income load. Flushed on any
            // write (incl. set()), off for artisan commands.
            $row = \App\Support\RequestMemo::remember('setting.' . $key, fn () => static::where('key', $key)->first());
            return $row ? $row->value : $default;
        } catch (\Illuminate\Database\QueryException) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        try {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::error("Setting::set failed for key [{$key}]: " . $e->getMessage());
        }
    }
}
