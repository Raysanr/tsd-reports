<?php

/*
 * Pancake seller-account names that should NEVER count an order as a real
 * upsell/cross-sell, no matter what tags the order carries. These are known
 * non-TSA accounts (admin/QA/internal) that sometimes touch orders directly
 * in Pancake — a tag like "UPSELL TSD - ..." added under one of these
 * accounts isn't a genuine TSA sale, so SyncTodayOrders treats an order as
 * a plain lead instead (see SyncTodayOrders::isExcludedUpsellSeller()).
 *
 * Matching is case-insensitive substring against the upsell item's
 * assigning_seller.name, same convention as tsa_shifts.seller_keywords_array.
 *
 * Root-caused 2026-08-21: order #1352836, account "Ralph Cruz" — not a TSA
 * (not in tsa_shifts, no seller_keywords match), yet its upsell tag still
 * made is_upsell true, counting ₱800 as cross-sell revenue with no TSA to
 * credit it to.
 */
return [
    'ralph cruz',
];
