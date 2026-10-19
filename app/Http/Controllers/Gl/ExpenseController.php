<?php

namespace App\Http\Controllers\Gl;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\Safe;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Cash\ExpenseFlow;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * سندات المصروف — المحاسب بيسجّل المصروف وصورته، والقيد بيتولّد
 * تلقائياً من `ExpenseObserver`. الإلغاء بيشيل القيد، ومافيش تعديل
 * على سند مرحّل: الغِ وسجّل تاني (أثر التدقيق بيفضل).
 *
 * Lane 4 (L4-D7..D9): بند وخزنة ومركز تكلفة، ودورة اعتماد —
 * `pending → approved → posted`. القيد بيتولّد عند الدفع بس.
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = Expense::with(['account', 'paidFromUser', 'payeeSupplier', 'payeeUser', 'category', 'safe', 'costCenter', 'rep', 'vehicle'])
            ->tap(fn ($q) => $range->apply($q, 'date'))
            ->when($request->filled('account'), fn ($q) => $q->where('account_id', $request->integer('account')))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()));

        // ⚠️ **المجموع والعدد لازم يكونوا على نفس المجموعة.** المجموع
        // كان مقفول على `posted` والعدد بياخد من الـpaginator (كل
        // الحالات) — يعني فلتر «ملغي» كان بيوري ٣ سندات بمجموع صفر،
        // والمحاسب يفتكر إن فيه سندات مبلغها ضاع. لو فيه فلتر حالة
        // بنحترمه، وغير كده المرحّل هو الافتراضي.
        $money = $request->filled('status') ? (clone $q) : (clone $q)->where('status', 'posted');

        // ═══ تصدير كل النتيجة المفلترة (٢٢/٩) — نفس الكويري قبل الباجينيشن، وإجماليه = كارت الإجمالي ═══
        if ($request->boolean('export')) {
            $all = (clone $q)->orderByDesc('date')->orderByDesc('id')->limit(5000)->get();

            return \App\Support\Csv::download('expenses-'.now()->format('Y-m-d-Hi').'.csv',
                [__('gl.number'), __('common.date'), __('cash.exp_category'), __('gl.code'), __('gl.account'), __('gl.payee'), __('gl.paid_from'),
                    __('cash.safe'), __('cash.cost_center'), __('gl.rep'), __('cash.exp_vehicle'),
                    __('gl.reference'), __('gl.status'), __('cash.exp_receipt'), __('gl.amount'), __('gl.note')],
                $all->map(fn ($x) => [
                    $x->number, $x->date?->format('Y-m-d'), $x->category?->displayName() ?? '', (string) $x->account?->code, $x->account?->displayName() ?? '',
                    $x->payeeLabel(), __('gl.paid_'.$x->paid_from).($x->paidFromUser ? ' — '.$x->paidFromUser->displayName() : ''),
                    $x->safe?->displayName() ?? '', $x->costCenter?->displayName() ?? '', $x->rep?->displayName() ?? '', (string) $x->vehicle?->plate,
                    (string) $x->reference, __('cash.xstatus_'.$x->status), $x->attachment_path ? __('cash.yes') : __('cash.no'),
                    \App\Support\Csv::money($x->amount), (string) $x->note,
                ]),
                [__('gl.total_posted'), '', '', '', '', '', '', '', '', '', '', '', '', '', \App\Support\Csv::money((clone $money)->sum('amount')), ''],
                \App\Support\Csv::meta(__('gl.expenses'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('gl.expenses', [
            'range' => $range,
            'rows' => (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'total' => (float) (clone $money)->sum('amount'),
            'count' => (int) (clone $money)->count(),
            // L4-D7: المعلّق بيستنى اعتماد والمعتمد بيستنى دفع — على كل التواريخ
            // (طلب اعتماد من الشهر اللي فات مايستخباش ورا فلتر الشهر)
            'pending' => Expense::where('status', 'pending')->count(),
            'pendingAmount' => (float) Expense::where('status', 'pending')->sum('amount'),
            'awaitingPay' => Expense::where('status', 'approved')->count(),
            // لينك الكارت بيغطي كل المعلّق: من أقدم تاريخ لأحدث تاريخ
            'pendingFrom' => self::ymd(Expense::where('status', 'pending')->min('date')),
            'pendingTo' => self::ymd(Expense::where('status', 'pending')->max('date')),
            'statuses' => Expense::STATUSES,
            'accounts' => GlAccount::whereIn('type', ['expense', 'asset'])->where('is_postable', true)->where('active', true)->orderBy('code')->get(),
            'categories' => ExpenseCategory::with('account')->where('active', true)->orderBy('type')->orderBy('code')->get(),
            'safes' => Safe::where('active', true)->orderBy('kind')->orderBy('code')->get(),
            'centers' => CostCenter::where('active', true)->orderBy('code')->get(),
            'vehicles' => Vehicle::where('active', true)->orderBy('plate')->get(['id', 'plate', 'rep_id']),
            'reps' => User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'employees' => User::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
        ]);
    }

    private static function ymd(mixed $d): ?string
    {
        return $d ? Carbon::parse($d)->toDateString() : null;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            // L4-D8/L4-D9: البند اختياري على السيرفر (السكريبتات القديمة) والشاشة بتطلبه
            'category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('active', 1)],
            // ⚠️ الحساب لازم يكون **فرعي شغّال** — الشرط في قاعدة `exists`
            // نفسها عشان الرسالة توصل على الخانة. النوع (مصروف، أو أصل لبند
            // أصل) بيتفحص تحت بعد ما نعرف البند. من غير حساب = حساب البند.
            'account_id' => ['required_without:category_id', 'nullable', 'integer', Rule::exists('gl_accounts', 'id')->whereIn('type', ['expense', 'asset'])->where('is_postable', 1)->where('active', 1)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            // الخزنة المسمّاة بتحدد `paid_from` من نوعها (L4-D4)؛ من غيرها الاختيار القديم
            'safe_id' => ['nullable', 'integer', Rule::exists('safes', 'id')->where('active', 1)],
            'paid_from' => ['required_without:safe_id', 'nullable', Rule::in(Expense::PAID_FROM)],
            // ⚠️ **مش `exists:users,id` وبس.** المفتاح ده بيوصل لـ
            // `GlAccount::repCash()` اللي بتفتح حساب نقدية جديد لأي
            // يوزر بيتبعت — يعني ريكوست متظبط كان بيولّد «نقدية مع
            // المحاسب» تحت أب نقدية المناديب في الشجرة. القايمة
            // مقفولة على الموظف الميداني النشط زي سيلكت الشاشة بالظبط.
            'paid_from_user_id' => ['required_if:paid_from,rep_cash', 'nullable', Rule::exists('users', 'id')->whereIn('role', User::FIELD_WORK_ROLES)->where('active', 1)],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')],
            // ربط البنزين (L4-D8 `links_vehicle`) — نفس قفل الموظف الميداني
            'rep_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', User::FIELD_WORK_ROLES)->where('active', 1)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')],
            'asset_name' => ['nullable', 'string', 'max:120'],
            'asset_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'asset_start_date' => ['nullable', 'date'],
            'payee_type' => ['required', Rule::in(Expense::PAYEE_TYPES)],
            'payee_supplier_id' => ['required_if:payee_type,supplier', 'nullable', 'exists:suppliers,id'],
            'payee_user_id' => ['required_if:payee_type,employee', 'nullable', 'exists:users,id'],
            'payee_name' => ['required_if:payee_type,other', 'nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:250'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $date = Carbon::parse($data['date']);
        if (GlPeriod::isClosed($date)) {
            return back()->withErrors(['date' => __('gl.period_closed', ['period' => GlPeriod::keyFor($date)])])->withInput();
        }

        // ═══ الحساب: المختار، وإلا حساب البند — ونوعه لازم يطابق نوع البند ═══
        $cat = ! empty($data['category_id']) ? ExpenseCategory::find($data['category_id']) : null;
        $accountField = ! empty($data['account_id']) ? 'account_id' : 'category_id';
        $accountId = ! empty($data['account_id']) ? (int) $data['account_id'] : $cat?->account_id;
        if (! $accountId) {
            return back()->withErrors(['category_id' => __('cash.exp_category_no_account')])->withInput();
        }
        $wantType = $cat?->type === 'asset' ? 'asset' : 'expense';
        $acc = GlAccount::find($accountId);
        if (! $acc || ! $acc->is_postable || ! $acc->active || $acc->type !== $wantType) {
            return back()->withErrors([$accountField => __($wantType === 'asset' ? 'cash.exp_account_asset' : 'cash.exp_account_expense')])->withInput();
        }

        // ═══ الخزنة و`paid_from` — نقدية المندوب مش خزنة ═══
        $repCash = ($data['paid_from'] ?? null) === 'rep_cash';
        $safe = ! $repCash && ! empty($data['safe_id']) ? Safe::find($data['safe_id']) : null;
        $paidFrom = $repCash ? 'rep_cash' : ($safe ? ExpenseFlow::paidFromFor($safe) : $data['paid_from']);
        $legacySafe = $safe ?? ($repCash ? null : Safe::forLegacy($paidFrom));

        // ═══ مركز التكلفة: المختار ← مركز الخزنة ← الشركة (L4-D9) ═══
        $centerId = $data['cost_center_id'] ?? $legacySafe?->cost_center_id ?? CostCenter::company()->id;

        // ═══ البنزين: المندوب والعربية بيتحفظوا لبند مربوط بعربية بس ═══
        // من غير مندوب مكتوب: مندوب النقدية · من غير عربية: عربية المندوب
        $links = (bool) $cat?->links_vehicle;
        $repId = $links ? ($data['rep_id'] ?? ($repCash ? $data['paid_from_user_id'] : null)) : null;
        $vehicleId = $links ? ($data['vehicle_id'] ?? ($repId ? Vehicle::where('rep_id', $repId)->where('active', true)->value('id') : null)) : null;

        // ═══ الأصل (L4-D10): بيانات الأصل لبند من نوع أصل بس ═══
        $isAsset = $cat?->type === 'asset';

        $x = app(ExpenseFlow::class)->create([
            'date' => $date->toDateString(),
            'category_id' => $cat?->id,
            'account_id' => $accountId,
            'amount' => round((float) $data['amount'], 2),
            'paid_from' => $paidFrom,
            // ⚠️ الخانات المشروطة بتتصفّر لو الشرط اتغيّر — المستخدم
            // بيختار «نقدية مندوب» ويرجع «الخزنة» والسيلكت القديم لسه
            // متعبّي، ومن غير التصفير ده بيتحفظ مندوب على سند خزنة
            'paid_from_user_id' => $repCash ? $data['paid_from_user_id'] : null,
            'safe_id' => $safe?->id,
            'cost_center_id' => $centerId,
            'rep_id' => $repId,
            'vehicle_id' => $vehicleId,
            'asset_name' => $isAsset ? ($data['asset_name'] ?? null) : null,
            'asset_life_months' => $isAsset ? ($data['asset_life_months'] ?? $cat->life_months) : null,
            'asset_start_date' => $isAsset ? ($data['asset_start_date'] ?? $date->toDateString()) : null,
            'payee_type' => $data['payee_type'],
            'payee_supplier_id' => $data['payee_type'] === 'supplier' ? $data['payee_supplier_id'] : null,
            'payee_user_id' => $data['payee_type'] === 'employee' ? $data['payee_user_id'] : null,
            'payee_name' => $data['payee_type'] === 'other' ? $data['payee_name'] : null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'attachment_path' => $request->hasFile('attachment') ? $request->file('attachment')->store('gl-attachments', 'public') : null,
        ], $request->user());

        return redirect()->route('gl.expenses')
            ->with('ok', __($x->status === 'posted' ? 'cash.exp_saved_paid' : 'cash.exp_sent_for_approval'));
    }

    /** اعتماد (L4-D7) — `act.gl.expense_approve` */
    public function approve(Request $request, Expense $expense)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:250']]);
        try {
            app(ExpenseFlow::class)->approve($expense, $request->user(), $data['note'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['expense' => $e->getMessage()]);
        }

        return back()->with('ok', __('cash.exp_approved'));
    }

    /** رفض — السبب إجباري عشان صاحب الطلب يعرف ليه */
    public function reject(Request $request, Expense $expense)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:250']]);
        try {
            app(ExpenseFlow::class)->reject($expense, $request->user(), $data['note']);
        } catch (Rejected $e) {
            return back()->withErrors(['expense' => $e->getMessage()]);
        }

        return back()->with('ok', __('cash.exp_rejected'));
    }

    /** دفع سند معتمد — هنا بس بيتولّد القيد ويتخصم من الخزنة */
    public function pay(Request $request, Expense $expense)
    {
        $data = $request->validate(['safe_id' => ['nullable', 'integer', Rule::exists('safes', 'id')->where('active', 1)]]);
        try {
            app(ExpenseFlow::class)->pay($expense, $request->user(), isset($data['safe_id']) ? (int) $data['safe_id'] : null);
        } catch (Rejected $e) {
            return back()->withErrors(['expense' => $e->getMessage()]);
        }

        return back()->with('ok', __('cash.exp_paid'));
    }

    public function void(Request $request, Expense $expense)
    {
        if ($expense->status === 'void') {
            return back();
        }
        // المرفوض خرج من الدورة خلاص — مفيش حاجة تتلغي
        if ($expense->status === 'rejected') {
            return back()->withErrors(['void' => __('cash.exp_rejected_no_void')]);
        }
        // ⚠️ الفترة المقفولة مابتتلمسش — الإلغاء هنا كان هيمسح قيد جوه
        // شهر متقفل بميزانه المعتمد. الصح سند عكسي بتاريخ مفتوح.
        // المعلّق والمعتمد مالهمش قيد، فإلغاؤهم مابيلمسش الفترة.
        if ($expense->status === 'posted' && GlPeriod::isClosed($expense->date)) {
            return back()->withErrors(['void' => __('gl.void_closed_hint')]);
        }
        // L4-D10: سند أصل اتهلك مرفوض — والأصل من غير إهلاك بيتمسح معاه
        try {
            app(ExpenseFlow::class)->void($expense, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        return back()->with('ok', __('gl.voided'));
    }
}
