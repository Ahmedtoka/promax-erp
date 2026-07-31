<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════
 * تقرير البضاعة والصلاحيات — دمج شيت الباتشات مع كتالوج GS1
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ ده تقرير **مقروء من ملف**، مش من الداتابيز. مقصود كده:
 * المصدر هو جرد الباتشات اللي المصنع بيطلعه + كتالوج الباركود الرسمي،
 * والاتنين لقطة على تاريخ محدّد. الأرقام هنا **مش** بتتحرك مع البيع،
 * وده الفرق بينها وبين شاشة المخزون (erp.stock) اللي بتقرا من stocks
 * وشاشة الصلاحية (wh.expiry) اللي بتقرا من batch_locations.
 *
 * ⚠️ السعر **مجمّد** في الملف على قيمة products.price_new وقت التوليد،
 * مش بيتقرا من Pricing وقت العرض. ده مقصود عشان اللقطة تفضل متسقة، بس
 * معناه إن لو الأسعار اتغيرت، الصفحة دي هتفضل بالسعر القديم لحد ما
 * تولّد الملف تاني. أي رقم "حي" لازم ييجي من erp.stock مش من هنا.
 *
 * الملف: storage/app/data/batch_report.json
 * بيتولّد من الشيتين. لو جالك شيت جديد، ولّده تاني بنفس الشكل.
 *
 * التواريخ في الشيت الأصلي d/m/Y، وإكسل قلب اليوم والشهر في الخلايا
 * اللي اليوم فيها ≤ 12. الفك اتعمل وقت التوليد، والملف هنا تواريخه سليمة.
 */
class BatchReportController extends Controller
{
    private const SOURCE = 'data/batch_report.json';

    public function index(Request $request)
    {
        $data = $this->load();

        if ($data === null) {
            return view('erp.batches_none');
        }

        $items = collect($data['items']);

        // ===== الفلاتر =====
        $filters = $request->only(['q', 'family', 'state']);

        if ($s = trim((string) $request->string('q'))) {
            $items = $items->filter(fn ($i) => str_contains($i['name'], $s)
                || stripos($i['name_en'], $s) !== false
                || str_contains($i['barcode'], $s)
                || str_contains($i['code'], $s));
        }
        if ($fam = $request->string('family')->value()) {
            $items = $items->where('family', $fam);
        }
        if ($state = $request->string('state')->value()) {
            // ⚠️ الفلتر على مستوى **الباتش** مش الصنف. لو فلترنا بحالة الصنف
            // (أقرب باتش فيه)، اختيار "من غير تاريخ إنتاج" كان بيرجّع صفر صنف
            // رغم إن جدول الحالات فوقه بيقول 16 باتش — لأن حالة الصنف بتتحسب
            // من الباتشات المؤرَّخة بس. دلوقتي بنعرض أي صنف **فيه** باتش بالحالة دي.
            $items = $items->filter(fn ($i) => collect($i['batches'])
                ->contains(fn ($b) => $this->stateOfDays($b['days_left'], $data) === $state));
        }

        $items = $items->values();

        // ⚠️ الـ KPIs على الكتالوج **كله** مش المفلتر — نفس قاعدة شاشة المخزون.
        $all = collect($data['items']);

        return view('erp.batches', [
            'meta' => $data,
            'items' => $items,
            'all' => $all,
            'filters' => $filters,
            'families' => $all->groupBy('family')->map(fn ($g) => [
                'label' => $g->first()['family_ar'],
                'label_en' => $g->first()['family_en'],
                'skus' => $g->count(),
                'qty' => $g->sum('qty'),
                'value' => $g->sum('value'),
                'batches' => $g->sum('batch_count'),
            ])->all(),
            'kpi' => [
                'skus' => $all->count(),
                'batches' => $all->sum('batch_count'),
                'qty' => $all->sum('qty'),
                'value' => $all->sum('value'),
                'qty_hold' => $all->sum('qty_hold'),
                'value_hold' => $all->sum('value_hold'),
                'qty_live' => $all->sum('qty_live'),
                'value_live' => $all->sum('value_live'),
            ],
            'buckets' => $this->buckets($all, $data),
            'soonest' => $this->soonestBatches($all, 10),
        ]);
    }

    /**
     * حالة من عدد الأيام الفاضلة — الدالة الوحيدة اللي بتصنّف.
     * ⚠️ نفس الحدود بالظبط موجودة في الفيو كـ closure. لو غيّرت هنا غيّر هناك.
     */
    private function stateOfDays(?int $days, array $meta): string
    {
        if ($days === null) {
            return 'undated';
        }
        if ($days < 0) {
            return 'expired';
        }
        if ($days <= $meta['danger_days']) {
            return 'danger';
        }
        if ($days <= $meta['warn_days']) {
            return 'warn';
        }

        return 'ok';
    }

    /** توزيع الوحدات والقيمة على حالات الصلاحية — على مستوى الباتش مش الصنف */
    private function buckets(Collection $items, array $meta): array
    {
        $out = [];
        foreach (['expired', 'danger', 'warn', 'ok', 'undated'] as $k) {
            $out[$k] = ['qty' => 0, 'value' => 0.0, 'batches' => 0];
        }

        foreach ($items as $item) {
            foreach ($item['batches'] as $b) {
                $days = $b['days_left'];
                $k = $days === null ? 'undated'
                    : ($days < 0 ? 'expired'
                    : ($days <= $meta['danger_days'] ? 'danger'
                    : ($days <= $meta['warn_days'] ? 'warn' : 'ok')));

                $out[$k]['qty'] += $b['qty'];
                $out[$k]['value'] += $b['value'];
                $out[$k]['batches']++;
            }
        }

        return $out;
    }

    /**
     * أقرب الباتشات للانتهاء عبر كل الأصناف.
     *
     * ⚠️ الهولد مستبعد هنا **عن قصد** — لازم يطابق حقل soonest اللي على الصنف
     * (وهو محسوب من الباتشات القابلة للبيع بس). لو سمحنا للهولد يدخل، ممكن
     * باتش محجوز يبقى هو الأقرب فيظهر في الـ KPI بينما شارة الصنف بتقول رقم تاني.
     */
    private function soonestBatches(Collection $items, int $take): array
    {
        return $items
            ->flatMap(fn ($i) => collect($i['batches'])
                ->filter(fn ($b) => $b['days_left'] !== null && ! $b['hold'])
                ->map(fn ($b) => $b + [
                    'name' => $i['name'],
                    'code' => $i['code'],
                    'barcode' => $i['barcode'],
                    'family_ar' => $i['family_ar'],
                    'family_en' => $i['family_en'],
                    'unit' => $i['unit'],
                ]))
            ->sortBy('days_left')
            ->take($take)
            ->values()
            ->all();
    }

    private function load(): ?array
    {
        $path = storage_path('app/'.self::SOURCE);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['items']) ? $data : null;
    }
}
