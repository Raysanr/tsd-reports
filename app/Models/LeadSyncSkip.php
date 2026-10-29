<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A Pancake order the lead sync deliberately did not turn into a lead,
 *  and why — shown on Call Tracker's Sync Health page (2026-10-15). */
class LeadSyncSkip extends Model
{
    public const REASONS = [
        'tsa_tag'           => 'Already tagged with a TSA in Pancake',
        'logistics_note'    => 'Note says "Duplicated by logistics"',
        'logistics_creator' => 'Created by logistics staff',
        'quick_add'         => 'Order source is Quick add',
        'duplicate'         => 'Duplicate order (same phone + product, same day)',
    ];

    protected $fillable = ['pancake_order_id', 'reason', 'detail', 'customer_name', 'phone_number', 'item_name', 'creator_name', 'order_created_at'];

    protected $casts = ['order_created_at' => 'datetime'];

    public static function record(array $raw, string $reason, ?string $detail = null): void
    {
        $id = (string) ($raw['id'] ?? '');
        if ($id === '') {
            return;
        }

        static::updateOrCreate(['pancake_order_id' => $id], [
            'reason'           => $reason,
            'detail'           => $detail,
            'customer_name'    => $raw['bill_full_name'] ?? $raw['customer']['name'] ?? null,
            'phone_number'     => $raw['bill_phone_number'] ?? ($raw['customer']['phone_numbers'][0] ?? null),
            'item_name'        => $raw['items'][0]['variation_info']['name'] ?? $raw['items'][0]['product_name'] ?? null,
            'creator_name'     => $raw['creator']['name'] ?? null,
            'order_created_at' => isset($raw['inserted_at']) ? Carbon::parse($raw['inserted_at'], 'UTC')->setTimezone('Asia/Manila') : null,
        ]);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
