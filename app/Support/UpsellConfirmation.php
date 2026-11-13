<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * No Confirmation page (explicit request, 2026-11-13): after an upsell the
 * TSA asks the customer, on the call, to reply YES/CONFIRM in Messenger to
 * proceed. This reads the lead's Pancake conversation and checks whether
 * the CUSTOMER replied a confirmation after the upsell was added.
 *
 * Results on the lead (upsell_confirmation):
 * - confirmed   — after upsell_at the customer replied a confirm word or
 *   emoji, sent the 👍 like sticker, or reacted 👍/❤️ to the page's message;
 * - unconfirmed — readable conversation, no such reply (listed on the page);
 * - unreadable  — Pancake refused the conversation (page not on this
 *   account's token) — never listed, per the request.
 */
class UpsellConfirmation
{
    /** YES, CONFIRM(ED), OO, OPO, SIGE, OK/OKAY as whole words. */
    public const CONFIRM_PATTERN = '/\b(yes|confirm|confirmed|oo|opo|sige|ok|okay)\b/iu';

    /** Thumbs-up / heart / OK-hand / check-mark emoji also confirm. */
    public const CONFIRM_EMOJI_PATTERN = '/(\x{1F44D}|\x{2764}|\x{2665}|\x{1F44C}|\x{2705}|\x{2714})/u';

    /** Messenger's own "like" thumbs-up stickers (small, medium, large). */
    public const LIKE_STICKER_IDS = ['369239263222822', '369239343222814', '369239383222810'];

    /** Reaction names Pancake/Messenger may report instead of the emoji. */
    private const CONFIRM_REACTION_NAMES = ['like', 'love', 'yes', 'thumbs_up', 'thumbsup', 'heart'];

    public static function isConfirmation(string $text): bool
    {
        if (preg_match(self::CONFIRM_PATTERN, $text) || preg_match(self::CONFIRM_EMOJI_PATTERN, $text)) {
            return true;
        }

        // Misspellings customers really send (2026-11-13: "COMFIRM" left a
        // confirmed lead on the page): any word within 2 letters of
        // CONFIRM / CONFIRMED (comfirm, confrim, cofirm, confirmd, …), and
        // stretched yes/okay (yess, yesss, okey, oki, okie).
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (preg_match('/^(y+e+s+|o+k+(a+y+|e+y+|i+e*)?)$/u', $word)) {
                return true;
            }
            $len = mb_strlen($word);
            if ($len >= 6 && $len <= 10 && \in_array($word[0], ['c', 'k'], true)
                && min(levenshtein($word, 'confirm'), levenshtein($word, 'confirmed')) <= 2) {
                return true;
            }
        }

        return false;
    }

    /** Customer sent Messenger's thumbs-up "like" sticker. */
    public static function isLikeSticker(array $msg): bool
    {
        foreach ($msg['attachments'] ?? [] as $a) {
            if (!\is_array($a) || ($a['type'] ?? null) !== 'sticker') {
                continue;
            }
            $haystack = ($a['sticker_id'] ?? '') . ' ' . ($a['id'] ?? '') . ' ' . ($a['url'] ?? '');
            foreach (self::LIKE_STICKER_IDS as $id) {
                if (str_contains($haystack, $id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A confirming reaction (👍 / ❤️ / like / love) on one of the page's
     * messages — the customer reacting to the TSA's "reply YES" message.
     * Accepts the shapes Messenger APIs use: a list under `reactions` or a
     * single `reaction`, each a plain string or {reaction|emoji|type|name}.
     */
    public static function hasConfirmReaction(array $msg): bool
    {
        $reactions = $msg['reactions'] ?? ($msg['reaction'] ?? []);
        $reactions = \is_array($reactions) && !array_is_list($reactions) ? [$reactions] : (array) $reactions;

        foreach ($reactions as $r) {
            $value = \is_array($r) ? (string) ($r['reaction'] ?? $r['emoji'] ?? $r['type'] ?? $r['name'] ?? '') : (string) $r;
            if ($value === '') {
                continue;
            }
            if (\in_array(strtolower(trim($value)), self::CONFIRM_REACTION_NAMES, true) || preg_match(self::CONFIRM_EMOJI_PATTERN, $value)) {
                return true;
            }
        }

        return false;
    }

    /** Same rule as calls.js renderMessage(): staff reply or the page itself. */
    public static function isFromCustomer(array $msg): bool
    {
        $from = $msg['from'] ?? [];

        return empty($from['admin_id']) && empty($from['uid'])
            && !(isset($msg['page_id'], $from['id']) && (string) $from['id'] === (string) $msg['page_id']);
    }

    /**
     * When the upsell was added: this app's own successful upsell, else the
     * time an UPSELL tag first appeared in Pancake's history (upsell done
     * directly in POS). Null when it can't be told.
     */
    public static function upsellAt(Lead $lead, PancakeOrderTagApi $api): ?Carbon
    {
        $inApp = LeadActivity::where('lead_id', $lead->id)->where('type', 'upsell_added')
            ->whereNotNull('amount')->min('created_at');
        if ($inApp) {
            return Carbon::parse($inApp);
        }

        foreach (collect($api->getOrderHistories($lead->pancake_order_id) ?? [])->sortBy('updated_at') as $entry) {
            if (!isset($entry['tags']['new'], $entry['updated_at'])) {
                continue;
            }
            $hasUpsell = fn ($tags) => collect($tags)->contains(
                fn ($t) => stripos(\is_array($t) ? ($t['name'] ?? '') : (string) $t, 'UPSELL') !== false
            );
            if ($hasUpsell($entry['tags']['new']) && !$hasUpsell($entry['tags']['old'] ?? [])) {
                return Carbon::parse($entry['updated_at'], 'UTC')->setTimezone(config('app.timezone'));
            }
        }

        return null;
    }

    /** Check one lead and store the result. */
    public static function check(Lead $lead, PancakeConversationApi $conversations, PancakeOrderTagApi $orders): void
    {
        $lead->upsell_at ??= self::upsellAt($lead, $orders);
        $lead->upsell_confirmation_checked_at = now();

        if ($lead->upsell_at === null) {
            $lead->saveQuietly();
            return;
        }

        $result = $conversations->getMessages($lead->pancake_page_id, $lead->pancake_conversation_id);
        if (!$result['success']) {
            $lead->upsell_confirmation = 'unreadable';
            $lead->saveQuietly();
            return;
        }

        $proof = null;
        foreach ($result['messages'] as $msg) {
            $at = isset($msg['inserted_at']) ? Carbon::parse($msg['inserted_at'], 'UTC') : null;
            if ($at === null || $at->lt($lead->upsell_at)) {
                continue;
            }
            $text = trim(strip_tags((string) ($msg['original_message'] ?? $msg['message'] ?? '')));

            if (self::isFromCustomer($msg)) {
                if ($text !== '' && self::isConfirmation($text)) {
                    $proof = $text;
                } elseif (self::isLikeSticker($msg)) {
                    $proof = '👍 (like sticker)';
                }
            } elseif (self::hasConfirmReaction($msg)) {
                // Customer reacted to the page's own message.
                $proof = 'Reacted to: ' . ($text !== '' ? $text : 'page message');
            }
            if ($proof !== null) {
                break;
            }
        }

        $lead->upsell_confirmation = $proof !== null ? 'confirmed' : 'unconfirmed';
        $lead->upsell_confirmation_text = $proof !== null ? mb_substr($proof, 0, 255) : null;
        $lead->saveQuietly();
    }

    /** Leads with an upsell worth checking: in-app upsell or a real upsell order. */
    public static function candidates(Carbon $since)
    {
        $upsoldOrderIds = Order::where('pancake_created_at', '>=', $since)->get()
            ->filter(fn (Order $o) => Order::isBroadRealUpsell($o))->pluck('pancake_order_id');

        return Lead::where('pancake_created_at', '>=', $since)
            ->whereNotNull('pancake_page_id')->whereNotNull('pancake_conversation_id')
            ->where(fn ($q) => $q->whereNull('upsell_confirmation')->orWhere('upsell_confirmation', 'unconfirmed'))
            ->where(fn ($q) => $q->whereIn('pancake_order_id', $upsoldOrderIds)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('lead_activities')
                    ->whereColumn('lead_activities.lead_id', 'leads.id')
                    ->where('type', 'upsell_added')->whereNotNull('amount')));
    }
}
