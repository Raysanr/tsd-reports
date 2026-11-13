<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Support\LeadCallResults;
use App\Support\PancakeOrderTagApi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fills lead_call_results from Pancake's order history (read-only) for
 * leads created in a date range — past leads, plus a safety re-read of
 * recent ones. Safe to re-run: existing rows are kept, never duplicated.
 */
class BackfillLeadCallResults extends Command
{
    protected $signature = 'leads:backfill-call-results
        {--from= : First lead-created date (Y-m-d, Manila), default 7 days ago}
        {--to= : Last lead-created date (Y-m-d, Manila), default today}';

    protected $description = 'Record exact call results for leads from Pancake order history';

    public function handle(PancakeOrderTagApi $api): int
    {
        $from = Carbon::parse($this->option('from') ?: now()->subDays(7)->toDateString())->startOfDay();
        $to   = Carbon::parse($this->option('to') ?: now()->toDateString())->endOfDay();

        $leads = Lead::whereBetween('pancake_created_at', [$from, $to])->orderBy('id')->get();
        $this->info("Reading Pancake history for {$leads->count()} leads ({$from->toDateString()} – {$to->toDateString()})…");

        $added = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($leads->count());

        foreach ($leads as $lead) {
            $histories = $api->getOrderHistories($lead->pancake_order_id);
            if ($histories === null) {
                $failed++;
            } else {
                $added += LeadCallResults::recordFromHistories($lead, $histories);
            }
            $bar->advance();
            usleep(200_000); // gentle on Pancake's API
        }

        $bar->finish();
        $this->newLine();
        $this->info("Added {$added} call results. {$failed} orders couldn't be read from Pancake.");

        return self::SUCCESS;
    }
}
