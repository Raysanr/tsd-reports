<?php

namespace Tests\Unit;

use App\Models\Order;
use PHPUnit\Framework\TestCase;

/**
 * Explicit request (2026-08-24): Mariel's SINUXYL "Relief Bundle" upsells
 * were never counted because their real Pancake tag ("Sinuxyl Relief Bundle
 * Instructions") contains neither "UPSELL" nor "TSD" — hasUpsellTag()'s
 * regex could never catch it no matter how it was tuned. Covers the new
 * NAMED_UPSELL_TAG_PHRASES fallback plus a regression check that every
 * already-working "UPSELL TSD"/"TSD UPSELL" tag style (SH Naturals' own
 * per-product list, supplied 2026-08-24) still matches.
 */
class OrderUpsellTagTest extends TestCase
{
    public function test_recognizes_the_named_sinuxyl_relief_bundle_tag_with_no_upsell_or_tsd_word(): void
    {
        $this->assertTrue(Order::hasUpsellTag(['Sinuxyl Relief Bundle Instructions']));
        $this->assertTrue(Order::hasUpsellTag(['SINUXYL RELIEF BUNDLE (TAGGING)']));
    }

    /** @dataProvider realProductTagProvider */
    public function test_recognizes_every_sh_naturals_product_upsell_tag(string $tag): void
    {
        $this->assertTrue(Order::hasUpsellTag([$tag]));
    }

    public static function realProductTagProvider(): array
    {
        return [
            'Sinuxyl inhaler'      => ['TSD UPSELL SINUXYL INHALER (TAGGING)'],
            'Sinuxyl relief bundle' => ['SINUXYL RELIEF BUNDLE (TAGGING)'],
            'AudiCure ear relief balm' => ['UPSELL TSD (EAR RELIEF BALM)TAGGING'],
            'Ginseng Serum Belo set' => ['TSD UPSELL BELO SET (TAGGING)'],
            'Ginseng Serum Belo bundle' => ['TSD UPSELL BELO BUNDLE (TAGGING)'],
            'Scar Cream rose body oil' => ['UPSELL TSD ROSE BODY OIL'],
            'CanPro Guyabano drink' => ['UPSELL TSD CANPRO GUYABANO JUICE DRINK'],
        ];
    }

    public function test_a_plain_disposition_tag_is_not_mistaken_for_an_upsell(): void
    {
        $this->assertFalse(Order::hasUpsellTag(['CONFIRMED VIA CALL', 'MARIEL', 'CALL DROPPED']));
    }

    /** The one deliberately-omitted bare product name from
     *  NAMED_UPSELL_TAG_PHRASES (see Order::hasUpsellTag()'s own doc
     *  comment) — a plain product-name tag with no upsell wording must NOT
     *  match, since it's already covered by the UPSELL/TSD regex and
     *  listing the bare name too would only add false positives. */
    public function test_a_bare_product_name_tag_with_no_upsell_wording_does_not_match(): void
    {
        $this->assertFalse(Order::hasUpsellTag(['Sinuxyl Inhaler', 'Ear Relief Balm']));
    }

    public function test_multiple_tags_only_one_of_which_is_an_upsell_tag_still_matches(): void
    {
        $this->assertTrue(Order::hasUpsellTag(['MARIEL', 'SUNSCREEN PICTURE', 'Sinuxyl Relief Bundle Instructions']));
    }
}
