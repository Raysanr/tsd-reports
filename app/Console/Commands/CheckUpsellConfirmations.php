<?php

namespace App\Console\Commands;

use App\Support\PancakeConversationApi;
use App\Support\PancakeOrderTagApi;
use App\Support\UpsellConfirmation;
use Illuminate\Console\Command;

/**
 * Reads upsold leads' Messenger conversations (read-only) for a customer
 * YES/CONFIRM reply after the upsell — feeds the No Confirmation page.
 * Re-checks unconfirmed leads until they confirm or age out of --days;
 * least-recently-checked first, capped per run to stay gentle on Pancake.
 */
class CheckUpsellConfirmations extends Command
{
    protected $signature = 'leads:check-upsell-confirmations {--days=3} {--limit=60}
        {--show= : Print one order\'s raw Messenger messages (to inspect reaction data) and exit}';

    protected $description = 'Check upsold leads for a customer YES/CONFIRM reply in Messenger';

    public function handle(PancakeConversationApi $conversations, PancakeOrderTagApi $orders): int
    {
        if ($orderId = $this->option('show')) {
            $lead = \App\Models\Lead::where('pancake_order_id', $orderId)->firstOrFail();
            $result = $conversations->getMessages($lead->pancake_page_id, $lead->pancake_conversation_id);
            $this->line(json_encode(\array_slice($result['messages'] ?? [], -10), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');

            return self::SUCCESS;
        }

        $since = now()->subDays((int) $this->option('days'))->startOfDay();

        $leads = UpsellConfirmation::candidates($since)
            ->orderByRaw('upsell_confirmation_checked_at IS NOT NULL')
            ->orderBy('upsell_confirmation_checked_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($leads as $lead) {
            UpsellConfirmation::check($lead, $conversations, $orders);
            usleep(100_000);
        }

        $this->info("Checked {$leads->count()} upsold leads.");

        return self::SUCCESS;
    }
}
