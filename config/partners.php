<?php

/*
| Partners Data Management — the three partner apps (separate repos, Railway
| projects and databases). Each exposes its Income Statement pages and a JSON
| totals feed to this app over a shared bearer secret (the partner side calls
| it TSD_MAIN_FEED_SECRET). See App\Services\PartnerFeed.
*/
return [
    'secret'  => env('PARTNER_FEED_SECRET'),
    'timeout' => (int) env('PARTNER_FEED_TIMEOUT', 60),

    'partners' => [
        'peak-co' => ['name' => 'PEAK.CO', 'url' => env('PARTNER_PEAKCO_URL', 'https://peak-co-production.up.railway.app')],
        'mj'      => ['name' => 'M&J',     'url' => env('PARTNER_MJ_URL', 'https://mj-leads-production.up.railway.app')],
        'mlc'     => ['name' => 'MLC',     'url' => env('PARTNER_MLC_URL', 'https://mlc-leads-production.up.railway.app')],
    ],
];
