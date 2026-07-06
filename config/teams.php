<?php

/*
 * Each team entry:
 *   name       — display name
 *   order_team — literal string stored in orders.team / tsa_shifts.team (legacy sync
 *                writes this directly, not the slug key below — see SyncTodayOrders)
 *
 * Products are managed via the Product Management page (see app/Models/Product.php),
 * not here — this file only defines the teams themselves.
 */
return [
    'sh-naturals' => [
        'name'       => 'SH Naturals',
        'order_team' => 'SH Naturals',
    ],

    'eyecare' => [
        'name'       => 'Eyecare',
        'order_team' => 'Eyecare Team',
    ],
];
