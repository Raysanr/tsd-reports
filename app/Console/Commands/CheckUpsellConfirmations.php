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
    protected $signature = 'leads:check-upsell-confirmations {--days=3} {--limit=60}';

    protected $description = 'Check upsold leads for a customer YES/CONFIRM reply in Messenger';

    public function handle(PancakeConversationApi $conversations, PancakeOrderTagApi $orders): int
    {
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
