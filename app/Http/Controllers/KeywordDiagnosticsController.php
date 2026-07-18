<?php

namespace App\Http\Controllers;

use App\Support\KeywordConflicts;
use Illuminate\Http\Request;

class KeywordDiagnosticsController extends Controller
{
    public function index()
    {
        return view('keyword-diagnostics', [
            'tsaTagDuplicates'       => KeywordConflicts::tsaTagDuplicates(),
            'tsaSellerOverlaps'      => KeywordConflicts::tsaSellerOverlaps(),
            'productKeywordOverlaps' => KeywordConflicts::productKeywordOverlaps(),
        ]);
    }

    /** AJAX — powers the live "test this keyword" tool. */
    public function test(Request $request)
    {
        $data = $request->validate(['sample' => 'required|string|max:255']);
        $result = KeywordConflicts::testKeyword($data['sample']);

        return response()->json([
            'tsaByTag'    => $result['tsaByTag'] ? ['name' => $result['tsaByTag']->display_name, 'team' => $result['tsaByTag']->team] : null,
            'tsaBySeller' => collect($result['tsaBySeller'])->map(fn ($t) => ['name' => $t->display_name, 'team' => $t->team])->all(),
            'products'    => collect($result['products'])->map(fn ($p) => ['name' => $p->display_name, 'team' => $p->team])->all(),
        ]);
    }
}
