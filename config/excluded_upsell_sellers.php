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
 *
 * "AJ Dela Cruz" added 2026-10-13 (explicit request, same reasoning as
 * Ralph Cruz above — a known non-TSA account whose own touches on an
 * order should never count as a real TSA upsell, confirmed by name
 * rather than root-caused against one specific order this time). Name
 * corrected same day — initially entered as "AJ Dele Cruz" (misspelled),
 * which matched nothing in production since matching is substring-based
 * ("dele" is not a substring of the real account's "dela").
 */
return [
    'ralph cruz',
    'aj dela cruz',
];
