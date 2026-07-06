<?php

/*
 * Map of every known disposition tag → normalized key.
 * Keys are stored in orders.disposition.
 * Matching is case-insensitive and whitespace-tolerant.
 */
return [
    'CONFIRMED VIA CALL'                      => 'confirmed_via_call',
    'UPSELL W/ CONFIRMATION'                  => 'upsell_with_confirmation',
    'CALL BACK'                               => 'call_back',
    'CALL DROPPED'                            => 'call_dropped',
    'REPEAT ORDER WITH UPSELL STOCKS'         => 'repeat_order_upsell',
    'RUDE CUSTOMER'                           => 'rude_customer',
    'RELATIVES CONFIRMATION'                  => 'relatives_confirmation',
    'DUPLICATE FROM RESTOCKING (DFR)'         => 'dfr',
    'DOUBLE ORDER (ENCODED BY SYSTEM)'        => 'double_order',
    'FSD UNCLEARED ORDERS'                    => 'fsd_uncleared',
    'NOT ANSWERING'                           => 'not_answering',
    'UNATTENDED'                              => 'unattended',
    'INVALID NUMBER'                          => 'invalid_number',
];
