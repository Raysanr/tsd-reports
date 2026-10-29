<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Reads the three partner apps' raw-data feed (/api/tsd-feed/*) for Partners
 * Data Management, over a shared bearer secret (the partner side calls it
 * TSD_MAIN_FEED_SECRET). See PartnerSync for what's done with it.
 */
class PartnerFeed
{
    /** @return array<string, array{name: string, url: string}> */
    public static function partners(): array
    {
        return config('partners.partners');
    }

    public static function name(string $slug): string
    {
        return self::partners()[$slug]['name'];
    }

    public function configured(): bool
    {
        return (string) config('partners.secret') !== '';
    }

    /** One feed resource's rows; throws on any failure. */
    public function rows(string $slug, string $resource, array $query = []): array
    {
        $url = rtrim(self::partners()[$slug]['url'], '/') . '/api/tsd-feed/' . $resource;

        return Http::withToken((string) config('partners.secret'))
            ->acceptJson()
            ->timeout(config('partners.timeout', 60))
            ->retry(2, 1000, throw: false)
            ->get($url, $query)
            ->throw()
            ->json('rows', []);
    }
}
