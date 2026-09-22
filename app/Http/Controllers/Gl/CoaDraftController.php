<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Gl\CoaDraftAccount;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Services\Gl\CoaDraftImporter;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * مسودة شجرة حسابات العميل — ورقة شغل المالك (٢٢ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الملف المستلم بينزل زي ما هو (`CoaDraftImporter`)، والمالك بيعدّل من هنا:
 * الكود، الاسم (عربي/إنجليزي)، النوع، الترتيب، والأب. مفيش قيود ولا ترحيل ولا
 * ربط بحسابات النظام — ده بييجي بعد ما الشجرة تتقفل وتتدرس.
 *
 * الربط الوحيد: حساب `feed = sales_ka` بيعرض مبيعات أوامر التوريد، و`sales_van`
 * مبيعات فواتير العربيات — من كشف حساب العملاء بتاريخ القيد، صافي من غير ضريبة
 * (حساب الإيراد مابيشيلش ضريبة). عرض بس.
 */
class CoaDraftController extends Controller
{
    public function index(Request $request)
    {
        if (! CoaDraftAccount::ready()) {
            return view('gl.coa', ['ready' => false]);
        }

        $range = DateRange::fromRequest($request);
        $all = CoaDraftAccount::orderBy('sort')->orderBy('id')->get();

        // الشجرة مفرودة بالترتيب: [الحساب، العمق] — الرسم من غير تكرار ولا كويري لكل عقدة
        $kids = $all->groupBy(fn ($a) => (int) $a->parent_id);
        $flat = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$flat, $kids) {
            foreach ($kids[$parent] ?? [] as $a) {
                $flat[] = [$a, $depth];
                $walk($a->id, $depth + 1);
            }
        };
        $walk(0, 0);

        // حساب أبوه اتمسح بره الشاشة مايختفيش — بيبان في الآخر كجذر
        $shown = array_flip(array_map(fn ($x) => $x[0]->id, $flat));

        foreach ($all as $a) {
            if (! isset($shown[$a->id])) {
                $flat[] = [$a, 0];
            }
        }

        $feeds = $this->feeds($range);

        if ($request->boolean('export')) {
            return $this->export($flat, $feeds, $range);
        }

        $codes = $all->pluck('code')->filter();

        return view('gl.coa', [
            'ready' => true, 'flat' => $flat, 'range' => $range, 'feeds' => $feeds,
            'types' => $all->pluck('qb_type')->filter()->unique()->sort()->values(),
            'kpi' => [
                'total' => $all->count(),
                'roots' => $all->whereNull('parent_id')->count(),
                'no_code' => $all->filter(fn ($a) => (string) $a->code === '')->count(),
                'dup_codes' => $codes->count() - $codes->unique()->count(),
                'no_ar' => $all->filter(fn ($a) => (string) $a->name_ar === '')->count(),
            ],
            'dupCodes' => $codes->duplicates()->unique()->flip(),
            'filter' => $request->string('show')->value(),
        ]);
    }

    /**
     * مبيعات السيستم للحسابين المربوطين — من `transactions` بتاريخ القيد.
     *
     * @return array{sales_ka: float, sales_van: float, other: float, tax: float, gross: float}
     */
    private function feeds(DateRange $range): array
    {
        $q = DB::table('transactions')->where('kind', 'sale');
        $range->apply($q, 'date');

        $rows = $q->selectRaw('source_type, SUM(debit) g, SUM(COALESCE(tax, 0)) x')->groupBy('source_type')->get();
        $out = ['sales_ka' => 0.0, 'sales_van' => 0.0, 'other' => 0.0, 'tax' => 0.0, 'gross' => 0.0];

        foreach ($rows as $r) {
            $net = (float) $r->g - (float) $r->x;
            $key = match ($r->source_type) {
                PurchaseOrder::class => 'sales_ka',
                Invoice::class => 'sales_van',
                default => 'other',
            };
            $out[$key] += $net;
            $out['tax'] += (float) $r->x;
            $out['gross'] += (float) $r->g;
        }

        return $out;
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'replace' => ['nullable', 'boolean'],
        ]);

        // ⚠️ `Sheet` بيحدد النوع من الامتداد — الملف المرفوع اسمه المؤقت من غير امتداد
        $ext = strtolower($request->file('file')->getClientOriginalExtension()) ?: 'xlsx';
        $tmp = $request->file('file')->move(storage_path('app/imports'), 'coa-'.now()->format('YmdHis').'.'.$ext);

        try {
            $s = CoaDraftImporter::import($tmp->getPathname(), (bool) ($data['replace'] ?? false));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } finally {
            @unlink($tmp->getPathname());   // فيه أرصدة الشركة — مايفضلش على الديسك
        }

        return redirect()->route('gl.coa')->with('ok', __('coa.imported', [
            'n' => $s['rows'], 'roots' => $s['roots'], 'nocode' => $s['no_code'], 'dup' => $s['duplicates'],
        ]));
    }

    /** تعديل خانة واحدة — الشاشة بتحفظ أول ما تسيب الخانة */
    public function update(Request $request, CoaDraftAccount $account)
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(CoaDraftAccount::EDITABLE)],
            'value' => ['nullable', 'string', 'max:500'],
        ]);

        $value = trim((string) ($data['value'] ?? ''));

        if ($data['field'] === 'name' && $value === '') {
            return response()->json(['ok' => false, 'error' => __('coa.name_required')], 422);
        }

        if ($data['field'] === 'feed' && $value !== '' && ! in_array($value, CoaDraftAccount::FEEDS, true)) {
            return response()->json(['ok' => false], 422);
        }

        $max = ['code' => 40, 'name' => 190, 'name_ar' => 190, 'qb_type' => 60][$data['field']] ?? 500;
        $account->update([$data['field'] => $value === '' ? null : mb_substr($value, 0, $max)]);

        return response()->json(['ok' => true]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:coa_draft_accounts,id'],
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:190'],
            'name_ar' => ['nullable', 'string', 'max:190'],
            'qb_type' => ['nullable', 'string', 'max:60'],
        ]);

        $parentId = $data['parent_id'] ?? null;
        $a = CoaDraftAccount::create($data + ['sort' => CoaDraftAccount::nextSort($parentId)]);

        return redirect()->to(route('gl.coa').'#a'.$a->id)->with('ok', __('coa.added'));
    }

    /** فوق/تحت بين الإخوات، أو نقل تحت أب تاني */
    public function move(Request $request, CoaDraftAccount $account)
    {
        $data = $request->validate([
            'dir' => ['required', Rule::in(['up', 'down', 'parent'])],
            'parent_id' => ['nullable', 'integer', 'exists:coa_draft_accounts,id'],
        ]);

        if ($data['dir'] === 'parent') {
            $to = $data['parent_id'] ?? null;

            if ($to !== null && ((int) $to === $account->id || $account->hasDescendant((int) $to))) {
                return back()->withErrors(['parent_id' => __('coa.cannot_move_under_self')]);
            }

            $account->update(['parent_id' => $to, 'sort' => CoaDraftAccount::nextSort($to)]);

            return redirect()->to(route('gl.coa').'#a'.$account->id)->with('ok', __('coa.moved'));
        }

        // ترقيم الإخوات من جديد 1..n الأول — الترتيب في الملف ممكن يبقى متكرر
        $sibs = $account->siblings()->get()->values();
        $i = $sibs->search(fn ($s) => $s->id === $account->id);
        $j = $data['dir'] === 'up' ? $i - 1 : $i + 1;

        if ($i !== false && $j >= 0 && $j < $sibs->count()) {
            $order = $sibs->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];

            DB::transaction(function () use ($order) {
                foreach ($order as $n => $s) {
                    $s->update(['sort' => $n + 1]);
                }
            });
        }

        return redirect()->to(route('gl.coa').'#a'.$account->id);
    }

    /** المسح بيطلّع ولاد الحساب لأبوه — مفيش حساب بيضيع مع أبوه */
    public function destroy(CoaDraftAccount $account)
    {
        DB::transaction(function () use ($account) {
            foreach ($account->children as $kid) {
                $kid->update(['parent_id' => $account->parent_id, 'sort' => CoaDraftAccount::nextSort($account->parent_id)]);
            }

            $account->delete();
        });

        return redirect()->route('gl.coa')->with('ok', __('coa.deleted'));
    }

    private function export(array $flat, array $feeds, DateRange $range)
    {
        $rows = [];

        foreach ($flat as [$a, $depth]) {
            $rows[] = [
                $depth + 1, (string) $a->code, str_repeat('    ', $depth).$a->name, (string) $a->name_ar, (string) $a->qb_type,
                $a->balance === null ? '' : Csv::money($a->balance),
                $a->feed ? Csv::money($feeds[$a->feed]) : '',
                (string) $a->note, (string) $a->source_row, (string) $a->source_path,
            ];
        }

        return Csv::download('coa-draft-'.now()->format('Y-m-d-Hi').'.csv', [
            __('coa.c_level'), __('coa.c_code'), __('coa.c_name'), __('coa.c_name_ar'), __('coa.c_type'),
            __('coa.c_file_balance'), __('coa.c_system_sales'), __('coa.c_note'), __('coa.c_source_row'), __('coa.c_source_path'),
        ], $rows, null, Csv::meta(__('coa.page'), $range->from?->toDateString(), $range->to?->toDateString()));
    }
}
