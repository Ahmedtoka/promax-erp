<?php

namespace App\Http\Controllers;

use App\Models\PriceChangeDoc;
use App\Models\PriceChangeRun;

/**
 * سجل تعديلات الأسعار (Lane 13) — قراءة للأدمن والمحاسب.
 *
 * ⚠️ هيكل مبدئي (Task 4): القايمة بسيطة، والتفاصيل بتحوّل لصفحة
 * التعديل نفسها. الشاشة الكاملة (فلاتر/تصدير/تفاصيل مستند) في Task 5.
 */
class PriceChangeLogController extends Controller
{
    public function index()
    {
        return view('erp.price_changes', [
            'runs' => PriceChangeRun::with('user')->orderByDesc('id')->paginate(50),
        ]);
    }

    public function show(PriceChangeRun $run)
    {
        return redirect()->route('erp.prices.change', $run);
    }

    public function doc(PriceChangeRun $run, PriceChangeDoc $doc)
    {
        abort_unless((int) $doc->run_id === (int) $run->id, 404);

        return redirect()->route('erp.prices.change', $run);
    }
}
