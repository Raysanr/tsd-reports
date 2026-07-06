<?php

/*
 * Each team entry:
 *   name       — display name
 *   order_team — literal string stored in orders.team / tsa_shifts.team (legacy sync
 *                writes this directly, not the slug key below — see SyncTodayOrders)
 *   shop_id    — Pancake POS shop ID for this team's orders
 *   products   — product tag substrings that identify this team's orders
 *
 * Product matching: a tag is considered a product match when it contains
 * one of these strings (case-insensitive).
 */
return [
    'sh-naturals' => [
        'name'       => 'SH Naturals',
        'order_team' => 'SH Naturals',
        'products' => [
            'SINUXYL',
            'SINUVEX',
            'STEAMPACK',
            'AUDICURE',
            'GINSENG SERUM',
            'VITAMIN C TONER',
            'CANPRO JUICE DRINK',
            'BATH PACK',
            'SCAR CREAM',
            'MINI GB',
        ],
    ],

    'eyecare' => [
        'name'       => 'Eyecare',
        'order_team' => 'Eyecare Team',
        'products' => [
            'CLEARSIGHT',
            'PTERYGIUM',
            'GLAUCO FREE',
            'LUMIEYES',
            'VISIONEX',
            'VISION PRO',
        ],
    ],
];
