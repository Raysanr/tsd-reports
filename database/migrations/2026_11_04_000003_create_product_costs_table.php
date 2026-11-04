<?php

use App\Support\DataSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product Costs page (explicit request, 2026-11-04). Seeded once from the
 * LEGENDS tab of "TSD DATA SOURCE JULY 2026", in sheet order. That tab has
 * 190 rows for 160 variations: repeats differing only by case or spacing
 * are merged and the first row wins, which is the row the sheet's own
 * lookups return. Partners gets the table but none of these rows, because
 * its costs are its own.
 */
return new class extends Migration
{
    private const SHEET_ROWS = [
        ['1 Golden Buddha Oil', 30],
        ['1 Haplunas Healing Eye Cream', 35],
        ['1 Clear Sight + 1 Haplunas Healing Eye Cream', 65],
        ['1 Pterygium', 30],
        ['1 Clear Sight', 30],
        ['2 Pterygium', 60],
        ['2 Clear Sight', 60],
        ['2 Golden Buddha Oil', 60],
        ['2 Haplunas Healing Eye Cream', 70],
        ['2 Golden Buddha+ 1 Golden Buddha Oil', 70],
        ['3 Pterygium Drops + 1 Haplunas Healing Eye Cream', 125],
        ['2 Clearsight + 3 Haplunas Healing Eye Cream', 165],
        ['4 Pterygium', 120],
        ['3 Haplunas Healing Eye Cream', 105],
        ['2 Clearsight + 1 Haplunas Healing Eye Cream', 95],
        ['3 Audicure', 90],
        ['3 Pterygium Drops + 2 Haplunas Healing Eye Cream', 160],
        ['2 Golden Buddha', 40],
        ['1 Golden Buddha + 1 Golden Buddha Oil', 50],
        ['1 Clearsight + 1 Audicure', 60],
        ['1 Audicure', 30],
        ['1 Golden Buddha', 20],
        ['4 Clearsight', 120],
        ['3 Clearsight', 90],
        ['5 Clearsight', 150],
        ['3 Pterygium', 90],
        ['2 Golden Buddha + 2 Golden Buddha Oil', 100],
        ['3 Clearsight + 2 Audicure', 150],
        ['2 Pterygium Drops + 1 Haplunas Healing Eye Cream', 95],
        ['1 Clear sight + 2 Haplunas Healing Eye Cream', 100],
        ['1 Ear Relief Balm', 35],
        ['3 Ear Relief Balm', 105],
        ['4 Haplunas Healing Eye Cream', 140],
        ['2 Ear Relief Balm', 70],
        ['2 Golden Buddha + 3 Golden Buddha Oil', 130],
        ['3 Golden Buddha Oil', 90],
        ['1 Sinuxyl Inhaler', 36],
        ['2 Sinuxyl Inhaler', 72],
        ['2 Sinuxyl Spray + 2 Sinuxyl Inhaler', 152],
        ['1 Pterygium drops + 1 haplunas healing eye cream', 65],
        ['1 Sinuxyl Inhaler + 2 Sinuxyl Spray', 116],
        ['1 MINI GB', 20],
        ['1 MINI BO', 30],
        ['2 MINI BO', 40],
        ['4 MINI BO', 120],
        ['4 Blueberry Eyedrops', 123],
        ['2 Pterygium + 2 Haplunas', 140],
        ['1 Pterygium + 2 Haplunas Healing Eyecream', 105],
        ['3 Clear Sight', 90],
        ['1 VC Toner + 1 Tinted Sunscreen PC', 86],
        ['1 CanPro Guyabano Oil', 75],
        ['1 CanPro Guyabano Herbal Drink', 200],
        ['1 Turmeric Soap + 1 Tinted Sunscreen', 86],
        ['1 Turmeric Soap + 1 VC Toner + 1 Tinted Sunscreen', 116],
        ['1 VC Toner', 30],
        ['1 Belo Tinted Sunscreen', 56],
        ['1 Turmeric Soap + 1 VC Toner', 60],
        ['1 Ginseng Serum', 18],
        ['1 Turmeric Soap', 30],
        ['1 Belo Set + 1 Ginseng Serum', 134],
        ['1 Clear Sight 3.0', 30],
        ['1 Haplunas Healing Eye Cream (CS)', 35],
        ['2 Haplunas Healing Eye Cream (CS)', 70],
        ['3 Haplunas Healing Eye Cream (CS)', 105],
        ['4 Haplunas Healing Eye Cream (CS)', 140],
        ['1 Haplunas Healing Eye Cream (PT)', 35],
        ['2 Haplunas Healing Eye Cream (PT)', 70],
        ['3 Haplunas Healing Eye Cream (PT)', 105],
        ['4 Haplunas Healing Eye Cream (PT)', 140],
        ['5 Pterygium', 175],
        ['1 Glauco Free + 1 Haplunas Healing Eye Cream', 65],
        ['1 Glauco Free + 1 Haplunas Healing Eye Cream + 1 Lumicare Oil', 111],
        ['1 Lumicare Oil + 1 Haplunas Healing Eye Cream -(GLAUCO)', 81],
        ['1 Lumicare Oil -(GLAUCO)', 46],
        ['2 Lumicare Oil -(GLAUCO)', 92],
        ['3 Lumicare Oil -(GLAUCO)', 138],
        ['1 Clearsight 3.0 +1 Haplunas Healing Eye Cream', 65],
        ['1 Clearsight 3.0 + 1 Haplunas Healing Eye Cream + 1 Lumicare Oil', 111],
        ['1 Clearsight 3.0 + 1 Lumicare Oil', 76],
        ['1 Lumicare Oil + 1 Haplunas Healing Eye Cream -(CS)', 81],
        ['1 Lumicare Oil - (CS)', 46],
        ['2 Lumicare Oil - (CS)', 92],
        ['3 Lumicare Oil - (CS)', 138],
        ['1 Lumicare Oil + 1 Haplunas Healing Eye Cream - (PT)', 81],
        ['1 Lumicare Oil - (PT)', 46],
        ['2 Lumicare Oil - (PT)', 92],
        ['3 Lumicare Oil - (PT)', 138],
        ['1 Pterygium + 1 Haplunas Healing Eye Cream + 1 Lumicare Oil', 111],
        ['1 Glauco Free + 1 Lumicare Oil', 76],
        ['3 Sinuxyl Nasal Inhaler + 1 Audicure +1 Ear Relief Balm', 173],
        ['3 sinuxyl inhaler + sinuxyl nasal spray', 156],
        ['3 Guyabano oil', 150],
        ['1 Sinuxyl Roll On Oil Relief', 35],
        ['1 Sinuxyl Roll On Oil Relief + 1 Sinuxyl Inhaler', 71],
        ['2 Sinuxyl Roll On Oil Relief', 70],
        ['3 Sinuxyl Roll On Oil Relief', 105],
        ['1 Lumicare Oil + 1 Haplunas Healing Eye Cream ( Lumieyes)', 81],
        ['2 Lumicare Oil + 2 Haplunas Healing Eye Cream', 162],
        ['1 Haplunas Healing Eye Cream (Glauco Free)', 35],
        ['2 Lumicare Oil + 2 Haplunas Healing Eye Cream - (PT)', 162],
        ['1 Turmeric Soap + 1 VC Toner + 1 Tinted Sunscreen - (Belo Set)', 116],
        ['2 Rose Body Oil', 130],
        ['3 Rose Body Oil', 195],
        ['1 Belo set + 2 Turmeric soap', 176],
        ['1 Rose Body Oil', 65],
        ['3 Sinuxyl Inhaler +1 Sinuxyl Nasal Spray', 198],
        ['1 Lumicare Oil', 46],
        ['1 Lumicare Oil + 1 Haplunas Healing Eye Cream -(LUMIEYES)', 81],
        ['1 Haplunas Healing Eye Cream- (LumiEyes)', 35],
        ['3 Haplunas Healing Eye Cream- (LumiEyes)', 105],
        ['2 Haplunas Healing Eye Cream- (LumiEyes)', 70],
        ['1 Lumicare Oil (LumiEyes)', 46],
        ['3 Lumicare Oil + 3 Haplunas Healing Eye Cream -(GLAUCO)', 243],
        ['1 Lumicare Oil + 2 Haplunas Healing Eye Cream - (GLAUCO)', 116],
        ['4 LumicareOil (PT)', 184],
        ['1 Lumicare Oil + 2 Haplunas Healing Eye Cream - (PT)', 116],
        ['3 Lumicare Oil + 3 Haplunas Healing Eye Cream -(PT))', 243],
        ['2 Lumicare Oil + 2 Pterygium', 162],
        ['1 Lumicare Oil + 1 Haplunas Healing Eye Cream (Glauco)', 81],
        ['1 Lumicare Oil + 2 Haplunas Healing Eye Cream - (CS)', 116],
        ['1 Pterygium + 1 LumicareOil', 76],
        ['3 Haplunas Healing Eye Cream (Glauco Free)', 138],
        ['1 Haplunas Healing Eye Cream + 2 Clearsight', 95],
        ['1 Clearsight + HealingEyecream + Lumicare Oil', 111],
        ['2 Turmeric Soap', 60],
        ['1- Turmeric Soap', 30],
        ['2 CanPro Guyabano Oil', 70],
        ['1 Audicure + 2 Ear Relief Balm', 100],
        ['2 VC Toner', 60],
        ['5 Rose Body Oil', 175],
        ['3 Sinuxyl Inhaler', 108],
        ['1Sinuxyl Inhaler + 1 Sinuxyl', 65],
        ['1 Haplunas Healing Eye Cream + 1 Clearsight', 65],
        ['3 CanPro Guyabano Oil', 105],
        ['2 Clear Sight 3.0', 60],
        ['2 Lumicare Oil +1 Haplunas Healing Eyecream', 127],
        ['3 Turmeric Soap', 90],
        ['1 Canpro Herbal Bath packs', 75],
        ['2 AudiCure', 60],
        ['1- Ginseng Serum', 30],
        ['1 Tinted Sunscreen', 56],
        ['3 Ginseng Serum', 90],
        ['4 GinsengSerum', 120],
        ['2 Ginseng Serum', 60],
        ['4 Rose Body Oil', 140],
        ['2 Tinted Sunscreen', 112],
        ['1Relief Bundle + 1 sinuxylspray', 66],
        ['2 Sinuxyl Spray', 60],
        ['3 Ear relief Balm + 1 Audicure', 135],
        ['2 Sinuxyl Nasal Inhaler + 1Sinuxyl Spray', 102],
        ['3Turmeric soap +1Ginseng Serum', 120],
        ['3 Clear Sight 3.0', 90],
        ['2 Lumicare Oil + 2 Haplunas healingEyeCream-(CS)', 162],
        ['1 Haplunas Healing Eye Cream + 1 Pterygium', 65],
        ['2 Pterygium + 1 Haplunas Healing Eye Cream + 1 Lumicare Oil', 141],
        ['1 - VC Toner', 30],
        ['3 Sinuxyl Inhaler + 1 Sinuxyl Spray', 148],
        ['3 VC Toner', 90],
        ['1ReliefBundle + 1 Sinuxyl Spray', 111],
        ['1 Scar Cream', 45],
    ];

    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $table) {
            $table->id();
            $table->string('variation')->unique();
            $table->decimal('cost', 10, 2);
            $table->timestamps();
        });

        if (DB::getDefaultConnection() === DataSet::PARTNERS) {
            return;
        }

        $now = now();
        DB::table('product_costs')->insert(array_map(
            fn (array $row) => ['variation' => $row[0], 'cost' => $row[1], 'created_at' => $now, 'updated_at' => $now],
            self::SHEET_ROWS
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costs');
    }
};
