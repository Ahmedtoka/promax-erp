<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use App\Support\Csv;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * تحصيلات الميدان — كل قيود `collection` بطرقها وصور إثباتها
 * (٩ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **شاشة عرض ومطابقة، مش تسجيل.** التسجيل من الأبلكيشن أثناء
 * زيارة مفتوحة (`FieldApiController::collect`) أو من المكتب عبر
 * `ManualCollection::record` (المستند اليدوي / كارت العميل). المحاسب
 * هنا بيطابق الشيكات والتحويلات على صورها ومراجعها.
 *
 * ⭐ **المصدر (٩/٩/٢٠٢٦):** `source=field` زيارة · `source=rep` مكتبي
 * باسم مندوب · `source=direct` مباشر من العميل بلا مندوب (تحويل/شيك).
 * شاشة «التحصيلات المباشرة» (`erp.collections.direct`) هي نفس الشاشة
 * مقفولة على المباشر + عمود **الضرايب المخصومة تحت الحساب** (قيد
 * `taxded` المنفصل اللي `ManualCollection` بتسجّله بنفس التاريخ والمرجع).
 * `?export=1` بيطلّع CSV من نفس الكويري المفلترة.
 */
class CollectionController extends Controller
{
    public const SOURCES = ['field', 'rep', 'direct'];

    /** التحصيلات المباشرة — نفس الشاشة مقفولة على المصدر «مباشر» */
    public function direct(Request $request)
    {
        $request->query->set('source', 'direct');

        return $this->index($request, 'direct');
    }

    public function index(Request $request, string $mode = 'all')
    {
        $method = (string) $request->query('method', '');
        $repId = (int) $request->query('rep', 0);
        $source = (string) $request->query('source', '');
        $source = in_array($source, self::SOURCES, true) ? $source : '';
        $from = $request->query('from');
        $to = $request->query('to');
        $user = $request->user();

        // ═══ سكوب واحد للصفوف والإجماليات (إصلاح تدقيق ٩/٨) ═══
        //
        // ⚠️ **`method IS NOT NULL` مش زخرفة.** القيود المولّدة
        // أوتوماتيك (مقابل فاتورة الكاش، تحصيل تسليم الـPO، الداتا
        // المستوردة) كلها `collection` من غير طريقة — عرضها هنا كان
        // بيغرق الشاشة بصفوف «مكتب/—» مجموعها مش في أي كارت فوق.
        // الشاشة دي **للتحصيلات المسجّلة بإيد حد**: ميدان أو مكتب.
        //
        // ⚠️ **وسكوب المدير في الكويري مش بعد الـpaginate.**
        // `setCollection` بعد `paginate(50)` كان بيسيب العدّاد
        // والصفحات محسوبين من الشركة كلها — صفحة فاضية مكتوب عليها
        // «1–50 من 4,200». والأخطر: كروت الإجماليات كانت من غير
        // السكوب خالص، فمدير القناة بيشوف فلوس عملاء غيره —
        // مخالفة مباشرة لدوكترين سكوب الفريق (٨ أغسطس).
        $scoped = function () use ($user, $method, $repId, $source, $from, $to) {
            return Transaction::where('kind', 'collection')
                ->whereNotNull('method')
                ->when(in_array($method, Transaction::METHODS, true),
                    fn ($q) => $q->where('method', $method))
                // المصدر: زيارة / مندوب مكتبي / مباشر بلا أي مصدر
                ->when($source === 'field', fn ($q) => $q->where('source_type', Visit::class))
                ->when($source === 'rep', fn ($q) => $q->where('source_type', User::class))
                ->when($source === 'direct', fn ($q) => $q->whereNull('source_type'))
                ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
                // فلتر المندوب بمرساتيه الاتنين — تحصيل الميدان مصدره
                // `Visit`، والتحصيل اليدوي من المستند اليدوي مصدره
                // `User` (المندوب نفسه). قبل ٦/٩ كان بيشوف الزيارات بس
                ->when($repId > 0, fn ($q) => $q->where(function ($w) use ($repId) {
                    $w->where(fn ($v) => $v
                        ->where('source_type', Visit::class)
                        ->whereIn('source_id', Visit::where('user_id', $repId)->select('id')))
                    ->orWhere(fn ($m) => $m
                        ->where('source_type', User::class)
                        ->where('source_id', $repId));
                }))
                ->when(! in_array($user->role, ['admin', 'accountant'], true),
                    fn ($q) => $q->whereIn('client_id',
                        Client::visibleTo(Client::query(), $user)->select('id')));
        };

        // ⚠️ التصدير من **نفس الكويري المفلترة** قبل الباجينيشن —
        // الملف مرآة الفلتر مش الخمسين المعروضين
        if ($request->boolean('export')) {
            return $this->excel($scoped()->with(['client.group'])->latest()->limit(5001)->get(), $mode);
        }

        $rows = $scoped()->with(['client.group'])->latest()
            ->paginate(50)->withQueryString();

        // مين حصّل — زيارة ← مندوبها، وإلا «المكتب». دفعة واحدة
        // بدل كويري لكل صف.
        $visitIds = $rows->getCollection()
            ->where('source_type', Visit::class)->pluck('source_id')->unique();
        $repByVisit = Visit::with('user:id,name,code')->whereIn('id', $visitIds)
            ->get()->keyBy('id');

        // التحصيل اليدوي (المستند اليدوي) منسوب للمندوب مباشرة
        // بـ`source_type = User` — نجيبهم دفعة واحدة برضو
        $manualRepIds = $rows->getCollection()
            ->where('source_type', User::class)->pluck('source_id')->unique();
        $repByUser = User::whereIn('id', $manualRepIds)
            ->get(['id', 'name', 'code'])->keyBy('id');

        // الإجماليات من **نفس السكوب** — الكروت لازم تساوي مجموع
        // الجدول اللي تحتها، وإلا الشاشة بتكدب على المحاسب.
        $totals = $scoped()
            ->selectRaw('method, SUM(credit) total, COUNT(*) cnt')
            ->groupBy('method')
            ->get()->keyBy('method');

        // ⭐ الضرايب المخصومة تحت الحساب (المباشر بس) — قيد `taxded`
        // منفصل بنفس العميل والتاريخ والمرجع. الكارت من نفس فلتر
        // الفترة، والعمود بالمفتاح المركّب لصفوف الصفحة.
        $taxByKey = collect();
        $taxTotal = 0.0;
        if ($mode === 'direct') {
            $taxQ = Transaction::where('kind', 'taxded')->whereNull('source_type')
                ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
                ->when(! in_array($user->role, ['admin', 'accountant'], true),
                    fn ($q) => $q->whereIn('client_id',
                        Client::visibleTo(Client::query(), $user)->select('id')));
            $taxTotal = (float) (clone $taxQ)->sum('credit');
            $taxByKey = self::taxByKey((clone $taxQ)->whereIn('client_id', $rows->getCollection()->pluck('client_id')->unique())->get());
        }

        return view('erp.collections', [
            'mode' => $mode,
            'rows' => $rows,
            'repByVisit' => $repByVisit,
            'repByUser' => $repByUser,
            'totals' => $totals,
            'taxByKey' => $taxByKey,
            'taxTotal' => $taxTotal,
            'method' => $method,
            'repId' => $repId,
            'source' => $source,
            'from' => $from,
            'to' => $to,
            'reps' => User::whereIn('role', User::FIELD_ROLES)
                ->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /** مفتاح ربط قيد الضريبة بقيد التحصيل: عميل + تاريخ + مرجع */
    public static function taxKey(Transaction $t): string
    {
        return $t->client_id.'|'.$t->date?->toDateString().'|'.(string) $t->reference;
    }

    /** @return \Illuminate\Support\Collection<string, float> */
    private static function taxByKey($taxRows)
    {
        return $taxRows->groupBy(fn ($t) => self::taxKey($t))
            ->map(fn ($g) => (float) $g->sum('credit'));
    }

    /**
     * CSV التحصيلات — من نفس الكويري المفلترة. في المباشر عمود
     * الضرايب المخصومة من قيود `taxded` المطابقة.
     */
    private function excel($rows, string $mode)
    {
        $truncated = $rows->count() > 5000;
        $rows = $rows->take(5000);

        $visitIds = $rows->where('source_type', Visit::class)->pluck('source_id')->unique();
        $repByVisit = Visit::with('user:id,name,code')->whereIn('id', $visitIds)->get()->keyBy('id');
        $repByUser = User::whereIn('id', $rows->where('source_type', User::class)->pluck('source_id')->unique())
            ->get(['id', 'name', 'code'])->keyBy('id');

        $taxByKey = $mode === 'direct'
            ? self::taxByKey(Transaction::where('kind', 'taxded')->whereNull('source_type')
                ->whereIn('client_id', $rows->pluck('client_id')->unique())->get())
            : collect();

        $columns = [
            __('common.code'), __('client.client'), __('common.date'), __('ops.source'), __('ops.collected_by'),
            __('ops.method'), __('ops.reference'), __('client.cheque_bank'), __('client.cheque_due_short'),
        ];
        if ($mode === 'direct') {
            $columns[] = __('ops.tax_withheld_col');
        }
        $columns[] = __('common.total');
        $columns[] = __('ops.memo');

        $out = [];
        $sum = 0.0;
        $taxSum = 0.0;
        foreach ($rows as $t) {
            $visit = $t->source_type === Visit::class ? $repByVisit->get($t->source_id) : null;
            $rep = $t->source_type === User::class ? $repByUser->get($t->source_id) : $visit?->user;
            $sourceLabel = $t->source_type === Visit::class ? __('ops.source_field')
                : ($t->source_type === User::class ? __('ops.source_rep') : __('ops.source_direct'));
            $row = [
                $t->client?->code ?? '—',
                $t->client?->fullName() ?? '—',
                $t->date?->toDateString() ?? $t->created_at->toDateString(),
                $sourceLabel,
                $rep ? $rep->name.' ('.$rep->code.')' : __('ops.office_entry'),
                $t->methodLabel() ?? '—',
                (string) ($t->reference ?: ''),
                (string) ($t->cheque_bank ?: ''),
                $t->cheque_due?->format('Y-m-d') ?? '',
            ];
            if ($mode === 'direct') {
                $tax = (float) ($taxByKey->get(self::taxKey($t)) ?? 0);
                $taxSum += $tax;
                $row[] = Csv::money($tax);
            }
            $sum += (float) $t->credit;
            $row[] = Csv::money($t->credit);
            $row[] = (string) ($t->memo ?: '');
            $out[] = $row;
        }

        $totals = [__('common.total'), '', '', '', '', '', '', '', ''];
        if ($mode === 'direct') {
            $totals[] = Csv::money($taxSum);
        }
        $totals[] = Csv::money($sum);
        $totals[] = $truncated ? __('ops.export_truncated') : '';

        return Csv::download(($mode === 'direct' ? 'direct-collections-' : 'collections-').now()->format('Y-m-d-Hi').'.csv', $columns, $out, $totals);
    }
}
