<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Undo" for the Archiving System's bigger one-click changes (2026-11-20:
 * seven teams archived in one minute, nothing warned the chart would fall
 * apart). Before a change, capture() the fields it will touch; after it,
 * offer() flashes a 10-minute Undo button that puts those fields back.
 */
class ArchiveUndo
{
    public const MINUTES = 10;

    /** @param iterable<Model>|Model|null $models  @return list<array{model: class-string, id: int, attrs: array}> */
    public static function capture(iterable|Model|null $models, array $fields): array
    {
        $models = $models instanceof Model ? [$models] : ($models ?? []);
        $rows = [];
        foreach ($models as $m) {
            $rows[] = ['model' => $m::class, 'id' => $m->getKey(), 'attrs' => array_map(
                fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v,
                collect($fields)->mapWithKeys(fn ($f) => [$f => $m->getRawOriginal($f)])->all()
            )];
        }

        return $rows;
    }

    public static function offer(string $label, array ...$captures): void
    {
        $rows = array_merge(...$captures);
        if ($rows === []) {
            return;
        }
        $token = Str::random(32);
        Cache::put(self::key($token), ['user' => auth()->id(), 'label' => $label, 'rows' => $rows], now()->addMinutes(self::MINUTES));
        session()->flash('undo', ['token' => $token, 'label' => $label]);
    }

    /** Puts the captured fields back; the label, or null if expired / not yours. */
    public static function apply(string $token): ?string
    {
        $data = Cache::pull(self::key($token));
        if (! $data || $data['user'] !== auth()->id()) {
            return null;
        }
        foreach (array_reverse($data['rows']) as $row) {
            $row['model']::query()->find($row['id'])?->update($row['attrs']);
        }
        ActivityLogger::log('archive.undo', null, 'Undid: ' . $data['label']);

        return $data['label'];
    }

    private static function key(string $token): string
    {
        return 'archive-undo:' . $token;
    }
}
