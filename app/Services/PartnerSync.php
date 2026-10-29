<?php

namespace App\Services;

use App\Support\DataSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies each partner app's products, TSAs and orders into the partners
 * database (App\Support\DataSet), where TSD's own Data Management runs on
 * them. Every row is tagged with the partner's name as its team — that's
 * what turns the team filter into ALL / PEAK.CO / M&J / MLC.
 *
 * TSA keys and order ids are prefixed with the partner ("PEAK.CO:JOANA",
 * "peak-co:123") since two partners can use the same ones; an order's
 * tsa_name gets the same prefix so it still matches its TSA. Orders are
 * fetched incrementally from a saved (updated_at, id) cursor.
 */
class PartnerSync
{
    public const ORDER_PAGE = 1000;

    /** Order pages per run — the rest is picked up by the next run. */
    public const MAX_ORDER_PAGES = 50;

    public function __construct(private PartnerFeed $feed) {}

    /** @return array{products: int, tsas: int, orders: int} */
    public function sync(string $slug): array
    {
        return DataSet::onPartners(function () use ($slug) {
            $team = PartnerFeed::name($slug);

            return [
                'products' => $this->syncProducts($slug, $team),
                'tsas'     => $this->syncTsas($slug, $team),
                'orders'   => $this->syncOrders($slug, $team),
            ];
        });
    }

    private function syncProducts(string $slug, string $team): int
    {
        $rows = $this->feed->rows($slug, 'products');
        $this->upsertMapped($slug, 'product', 'products', array_map(fn ($r) => ['team' => $team] + $r, $rows));

        return count($rows);
    }

    private function syncTsas(string $slug, string $team): int
    {
        $rows = $this->feed->rows($slug, 'tsas');
        $this->upsertMapped($slug, 'tsa', 'tsa_shifts', array_map(
            fn ($r) => ['team' => $team, 'tsa_key' => self::tsaKey($team, $r['tsa_key'])] + $r,
            $rows
        ));

        return count($rows);
    }

    private function syncOrders(string $slug, string $team): int
    {
        $cursorKey = "partner_sync.{$slug}.orders_cursor";
        $cursor = json_decode((string) DB::table('settings')->where('key', $cursorKey)->value('value'), true) ?: [];
        $columns = Schema::getColumnListing('orders');
        $count = 0;

        for ($page = 0; $page < self::MAX_ORDER_PAGES; $page++) {
            $rows = $this->feed->rows($slug, 'orders', array_filter([
                'since' => $cursor['since'] ?? null, 'after_id' => $cursor['after_id'] ?? null, 'limit' => self::ORDER_PAGE,
            ]));
            if ($rows === []) {
                break;
            }

            $records = array_map(function (array $r) use ($slug, $team, $columns) {
                $record = array_intersect_key($r, array_flip($columns));
                unset($record['id']);
                $record['pancake_order_id'] = "{$slug}:{$r['pancake_order_id']}";
                $record['team'] = $team;
                $record['tsa_name'] = $r['tsa_name'] === null || $r['tsa_name'] === '' ? $r['tsa_name'] : self::tsaKey($team, $r['tsa_name']);

                return $record;
            }, $rows);

            foreach (array_chunk($records, 200) as $chunk) {
                DB::table('orders')->upsert($chunk, ['pancake_order_id'], array_values(array_diff(array_keys($chunk[0]), ['pancake_order_id', 'created_at'])));
            }

            $last = end($rows);
            $cursor = ['since' => $last['updated_at'], 'after_id' => $last['id']];
            DB::table('settings')->updateOrInsert(['key' => $cursorKey], ['value' => json_encode($cursor), 'updated_at' => now()]);
            $count += count($rows);

            if (count($rows) < self::ORDER_PAGE) {
                break;
            }
        }

        return $count;
    }

    /**
     * Inserts/updates rows by their partner id (partner_imports maps it to
     * the local id), then removes local rows no partner sent — including
     * anything the shared migrations seeded for TSD.
     */
    private function upsertMapped(string $slug, string $resource, string $table, array $rows): void
    {
        $columns = Schema::getColumnListing($table);

        DB::transaction(function () use ($slug, $resource, $table, $rows, $columns) {
            $map = DB::table('partner_imports')->where(['partner' => $slug, 'resource' => $resource])->pluck('local_id', 'source_id');

            foreach ($rows as $row) {
                $record = array_intersect_key($row, array_flip($columns));
                unset($record['id']);
                $localId = $map[$row['id']] ?? null;

                if ($localId !== null && DB::table($table)->where('id', $localId)->exists()) {
                    DB::table($table)->where('id', $localId)->update($record);
                } else {
                    $localId = DB::table($table)->insertGetId($record);
                    DB::table('partner_imports')->updateOrInsert(
                        ['partner' => $slug, 'resource' => $resource, 'source_id' => $row['id']],
                        ['local_id' => $localId, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            }

            $known = DB::table('partner_imports')->where('resource', $resource)->pluck('local_id');
            DB::table($table)->whereNotIn('id', $known)->delete();
        });
    }

    public static function tsaKey(string $team, string $key): string
    {
        return "{$team}:{$key}";
    }
}
