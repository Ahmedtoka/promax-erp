<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * سندات المصروف — المحاسب بيسجّل المصروف وصورته، والقيد بيتولّد
 * تلقائياً من `ExpenseObserver`. الإلغاء بيشيل القيد، ومافيش تعديل
 * على سند مرحّل: الغِ وسجّل تاني (أثر التدقيق بيفضل).
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = Expense::with(['account', 'paidFromUser', 'payeeSupplier', 'payeeUser'])
            ->tap(fn ($q) => $range->apply($q, 'date'))
            ->when($request->filled('account'), fn ($q) => $q->where('account_id', $request->integer('account')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()));

        return view('gl.expenses', [
            'range' => $range,
            'rows' => (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'total' => (float) (clone $q)->where('status', 'posted')->sum('amount'),
            'accounts' => GlAccount::where('type', 'expense')->where('is_postable', true)->where('active', true)->orderBy('code')->get(),
            'reps' => User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'employees' => User::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            // ⚠️ الحساب لازم يكون **مصروف فرعي شغّال** — الشرط في قاعدة
            // `exists` نفسها مش في تشيك بعدين، عشان الرسالة توصل على
            // الخانة والفورم يرجع بالقيم (اختيار الخزنة كان بيعدّي)
            'account_id' => ['required', Rule::exists('gl_accounts', 'id')->where('type', 'expense')->where('is_postable', 1)->where('active', 1)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'paid_from' => ['required', Rule::in(Expense::PAID_FROM)],
            'paid_from_user_id' => ['required_if:paid_from,rep_cash', 'nullable', 'exists:users,id'],
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

        Expense::create([
            'number' => Expense::nextNumber(),
            'date' => $date->toDateString(),
            'account_id' => $data['account_id'],
            'amount' => round((float) $data['amount'], 2),
            'paid_from' => $data['paid_from'],
            // ⚠️ الخانات المشروطة بتتصفّر لو الشرط اتغيّر — المستخدم
            // بيختار «نقدية مندوب» ويرجع «الخزنة» والسيلكت القديم لسه
            // متعبّي، ومن غير التصفير ده بيتحفظ مندوب على سند خزنة
            'paid_from_user_id' => $data['paid_from'] === 'rep_cash' ? $data['paid_from_user_id'] : null,
            'payee_type' => $data['payee_type'],
            'payee_supplier_id' => $data['payee_type'] === 'supplier' ? $data['payee_supplier_id'] : null,
            'payee_user_id' => $data['payee_type'] === 'employee' ? $data['payee_user_id'] : null,
            'payee_name' => $data['payee_type'] === 'other' ? $data['payee_name'] : null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'attachment_path' => $request->hasFile('attachment') ? $request->file('attachment')->store('gl-attachments', 'public') : null,
            'status' => 'posted',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('gl.expenses')->with('ok', __('gl.expense_saved'));
    }

    public function void(Request $request, Expense $expense)
    {
        if ($expense->status === 'void') {
            return back();
        }
        // ⚠️ الفترة المقفولة مابتتلمسش — الإلغاء هنا كان هيمسح قيد جوه
        // شهر متقفل بميزانه المعتمد. الصح سند عكسي بتاريخ مفتوح.
        if (GlPeriod::isClosed($expense->date)) {
            return back()->withErrors(['void' => __('gl.void_closed_hint')]);
        }
        $expense->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $request->user()->id]);

        return back()->with('ok', __('gl.voided'));
    }
}
