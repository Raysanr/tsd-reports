<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TagParser
{
    /**
     * Parse a raw tags array into structured fields.
     *
     * @param  array  $tags  e.g. ["JULIE", "UNATTENDED - EYECARE", "CLEARSIGHT"]
     * @return array{tsa_name: string|null, team: string|null, disposition: string|null, product: string|null}
     */
    public function parse(array $tags): array
    {
        $result = [
            'tsa_name'    => null,
            'team'        => null,
            'disposition' => null,
            'product'     => null,
        ];

        $normalizedTags = array_map(
            fn($t) => strtoupper(trim(preg_replace('/\s+/', ' ', $t))),
            $tags
        );

        $tsaMap         = $this->invertedMap(config('tsas.php', config('tsas', [])));
        $dispositionMap = $this->invertedMap(config('dispositions.php', config('dispositions', [])));
        $teams          = config('teams.php', config('teams', []));

        foreach ($normalizedTags as $raw => $tag) {
            // --- TSA match ---
            if ($result['tsa_name'] === null) {
                foreach ($tsaMap as $upperName => $key) {
                    if (str_contains($tag, $upperName)) {
                        $result['tsa_name'] = $key;
                        break;
                    }
                }
            }

            // --- Disposition match ---
            if ($result['disposition'] === null) {
                foreach ($dispositionMap as $upperDisp => $key) {
                    if (str_contains($tag, $upperDisp)) {
                        $result['disposition'] = $key;
                        break;
                    }
                }
            }

            // --- Team + Product match ---
            // config/teams.php no longer has a 'products' key at all (products moved
            // to the database-backed Product model — see TsaPerformanceController and
            // SyncTodayOrders, the actively-used sync path). The `?? []` here just
            // stops this legacy, unscheduled command from crashing; it means this
            // branch can no longer actually match a product tag.
            if ($result['team'] === null || $result['product'] === null) {
                foreach ($teams as $teamKey => $teamConfig) {
                    foreach ($teamConfig['products'] ?? [] as $productSubstring) {
                        if (str_contains($tag, strtoupper($productSubstring))) {
                            $result['team']    = $teamKey;
                            $result['product'] = $productSubstring;
                            break 2;
                        }
                    }
                }
            }
        }

        // Log any tag that contributed nothing — helps discover new dispositions/TSAs
        $unmatched = [];
        foreach ($normalizedTags as $tag) {
            if (!$this->tagMatched($tag, $tsaMap, $dispositionMap, $teams)) {
                $skip = ['UNCATERED LEADS', 'FOR CALLING', 'RESTOCKING'];
                $skip_it = false;
                foreach ($skip as $s) {
                    if (str_contains($tag, $s)) { $skip_it = true; break; }
                }
                if (!$skip_it) {
                    $unmatched[] = $tag;
                }
            }
        }

        if (!empty($unmatched)) {
            Log::debug('TagParser: unmatched tags', ['tags' => $unmatched]);
        }

        return $result;
    }

    /** Build an uppercase-key → value map for fast contains-matching. */
    private function invertedMap(array $map): array
    {
        $out = [];
        foreach ($map as $display => $key) {
            $out[strtoupper(trim($display))] = $key;
        }
        return $out;
    }

    private function tagMatched(string $tag, array $tsaMap, array $dispMap, array $teams): bool
    {
        foreach ($tsaMap as $name => $_) {
            if (str_contains($tag, $name)) return true;
        }
        foreach ($dispMap as $disp => $_) {
            if (str_contains($tag, $disp)) return true;
        }
        // See the matching comment in parse() above — 'products' no longer exists in
        // config/teams.php, so this can never actually match; the `?? []` only
        // prevents a crash in this legacy, unscheduled command.
        foreach ($teams as $teamConfig) {
            foreach ($teamConfig['products'] ?? [] as $p) {
                if (str_contains($tag, strtoupper($p))) return true;
            }
        }
        return false;
    }
}
