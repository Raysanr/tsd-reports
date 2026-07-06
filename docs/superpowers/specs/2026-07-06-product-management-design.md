# Product Management

## Problem

Products are currently hardcoded in `config/teams.php` as a plain array of match strings per team (e.g. SH Naturals' `SINUXYL`, `AUDICURE`, ...; Eyecare's `CLEARSIGHT`, `PTERYGIUM`, ...). Adding a new product (as we just did manually for VisionEx/Vision Pro after a debugging session) requires editing a PHP config file directly. There's also a separate, easy-to-miss `PRODUCT_TAG_OVERRIDES` const duplicated in two different files (`TsaPerformanceController` and `SyncTodayOrders`) to handle products whose real name doesn't literally contain their tag/keyword (e.g. "CanPro Guyabano Herbal Drink" only matches the tag "CANPRO").

Goal: a "Product Management" admin page — mirroring the existing "TSA Management" page — where products can be added, edited, and assigned to a team through the UI, backed by a database table instead of a config file.

## Data model

New `products` table (migration `create_products_table`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK | |
| `display_name` | string | e.g. "Canpro Juice Drink" |
| `match_keyword` | string, nullable | e.g. "CANPRO". When null, matching falls back to `display_name`. Replaces `PRODUCT_TAG_OVERRIDES` entirely. |
| `team` | string | Stores the literal `order_team` value (e.g. `"SH Naturals"`, `"Eyecare Team"`) — same convention already used by `tsa_shifts.team`. |
| `sort_order` | unsigned smallint, default 0 | Display ordering within a team group. |
| `created_at` / `updated_at` | timestamps | |

New `Product` model (`app/Models/Product.php`), with an `effective_keyword` accessor:
```php
public function getEffectiveKeywordAttribute(): string
{
    return $this->match_keyword ?: $this->display_name;
}
```

The migration's `up()` seeds all 16 current products (matching the `tsa_shifts` migration's seed-in-migration pattern) directly from today's `config/teams.php` contents — the original 14 plus VisionEx/Vision Pro — so behavior is identical the moment this ships.

Teams themselves are **not** managed through this feature — `config/teams.php` keeps `name` and `order_team` for the two teams; only products move to the database.

## UI

New page at `/product-management`, structurally identical to `/tsa-management`:
- Products grouped by team (SH Naturals section, Eyecare section), each showing Display Name and Match Keyword (if set).
- Add/Edit form: Display Name (required), Match Keyword (optional, placeholder text explains it defaults to Display Name), Team (dropdown, populated from `config('teams')`).
- Delete action per product (with the same "any TSA/product whose team doesn't match a configured team" orphan-handling pattern `TsaManagementController::index()` already uses, adapted for products whose team is unrecognized — should not happen in practice since the dropdown only offers configured teams, but the display logic stays defensive the same way the existing TSA page is).
- New sidebar link under CONFIG, next to "TSA Management" and "Settings", in `resources/views/layouts/app.blade.php`.

New products get `sort_order = max(sort_order) + 1` on creation, same pattern as `TsaManagementController::store()`.

New `ProductManagementController` (`index`, `store`, `update`, `destroy`), mirroring `TsaManagementController`'s structure and validation style. New routes in `routes/web.php`, mirroring the `tsa-management` routes:
```text
GET    /product-management
POST   /product-management
PUT    /product-management/{product}
DELETE /product-management/{product}
```

## Integration — replacing `config('teams')['products']` at its three call sites

1. **`TsaPerformanceController::index()`** — `$availableProducts` (the product filter dropdown) becomes `Product::where('team', $teamsConfig[$selectedTeam]['order_team'])->orderBy('sort_order')->get()`. The per-product `raw_tags` substring filter switches from `self::PRODUCT_TAG_OVERRIDES[$selectedProduct] ?? $selectedProduct` to looking up the matching `Product` row's `effective_keyword`.
2. **`SyncTodayOrders::inferTeamFromProduct()`** — the fix from the disposition/team debugging session — loops `Product::all()` (grouped by team) instead of `config('teams')`, same substring-match logic (`stripos($productName, $product->effective_keyword)`), just reading from the DB.
3. Both files' local `PRODUCT_TAG_OVERRIDES` consts are deleted — `match_keyword` replaces the concept for good, in one place instead of two.

## What does NOT change

- **Existing `orders` rows** — `product`, `team`, `tsa_name`, `disposition` are plain strings stored at sync time. This feature changes matching logic for *future* syncs only; it does not rewrite history, and does not require touching the `orders` table at all.
- **Current product list / current reports** — the migration seeds the exact same 16 products with the exact same effective keywords, so every report already verified during the earlier debugging session (TSA Performance totals, Excess/Catered numbers, the SH Naturals/Eyecare gap-closing work) shows identical numbers immediately after this ships.
- **Team creation** — out of scope per explicit decision; teams stay defined in `config/teams.php`.

## Testing

- Feature test: seeded products match `config/teams.php`'s pre-migration contents exactly (count + keywords per team).
- Feature test: `ProductManagementController` CRUD (store/update/destroy) with validation (display_name required, team must be one of the configured teams).
- Feature test: `SyncTodayOrders::inferTeamFromProduct()` correctly matches a product via `match_keyword` fallback (e.g. "CanPro Guyabano Herbal Drink" → SH Naturals via the "CANPRO" keyword), reusing the same fixtures already used to verify the July 5 orphan-order backfill.
- Manual verification: after migrating, re-run the TSA Performance report for July 5, SH Naturals and Eyecare, and confirm totals (131 / 152) are unchanged from before this feature shipped.
