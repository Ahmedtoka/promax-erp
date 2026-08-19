<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\User;
use App\Services\Returns;
use App\Support\Scope;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * المرتجعات من الـERP (٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **قبل الشاشة دي ماكانش فيه مسار مرتجع من الـERP خالص** — الطريقة
 * الوحيدة لكتابة قيد `return` كانت أبلكيشن مندوب بزيارة مفتوحة. يعني
 * أي مرتجع بييجي المخزن مباشرة، أو بيتفق عليه المكتب مع سلسلة، ماكانش
 * له مكان في السيستم فبيتسجّل «تسوية» يدوية في كشف الحساب من غير بنود.
 *
 * ⚠️ **بينده على نفس `App\Services\Returns` اللي الأبلكيشن بينده عليها**
 * — نفس التسعير من الفاتورة الأصلية، نفس السقف، نفس القيود. تفريع
 * المنطق هنا كان هيخلّي مرتجع الويب يطلع بأرقام غير مرتجع الأبلكيشن.
 *
 * ⚠️ **المندوب اختياري.** الافتراضي إن البضاعة رجعت المخزن مباشرة
 * (بلا عهدة وبلا `returned_in`). لو المكتب حدّد مندوب، معناها إن
 * البضاعة اتسلّمت في عربيته — وساعتها `Returns::create` بيطلب إن
 * يكون عنده عهدة مفتوحة النهارده وبينزّلها فيها زي مرتجع الأبلكيشن،
 * عشان تظهر في تصفيته. مندوب من غير عهدة = رفض برسالة واضحة.
 */
class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();

        // ⚠️ `client.group` محمّلة لأن `fullName()` بتعرض «السلسلة —
        // الفرع»، و`items` **مش** محمّلة لأن الجدول بيعرض المجاميع
        // المخزّنة على المستند مش البنود.
        $q = ClientReturn::with(['client.group', 'rep'])
            // ⚠️ سكوب التشانل مانجر — مرتجعات عملائه بس
            ->when($u?->role === 'manager',
                fn ($w) => $w->whereIn('client_id', Client::visibleTo(Client::query(), $u)->select('id')));

        if ($clientId = $request->integer('client')) {
            $q->where('client_id', $clientId);
        }
        if ($policy = $request->string('policy')->value()) {
            $q->where('policy', $policy);
        }
        if ($from = $request->string('from')->value()) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $q->whereDate('created_at', '<=', $to);
        }

        // ⚠️ الإجماليات من **نفس الكويري المفلترة** — نطاق واحد،
        // وقبل `paginate()` عشان تحسب الكل مش الصفحة.
        $scoped = clone $q;

        return view('ops.returns', [
            'returns' => $q->latest()->paginate(30)->withQueryString(),
            'sumValue' => (float) (clone $scoped)->sum('grand_total'),
            'sumGood' => (int) (clone $scoped)->sum('good_units'),
            'sumDamaged' => (int) (clone $scoped)->sum('damaged_units'),
            'policies' => ClientReturn::POLICIES,
            'filters' => $request->only(['client', 'policy', 'from', 'to']),
        ]);
    }

    /** شاشة إنشاء مرتجع لعميل — بتوري المتاح للرد بسعره الأصلي */
    public function create(Request $request)
    {
        $client = $request->filled('client')
            ? Client::find($request->integer('client'))
            : null;

        if ($client !== null) {
            Scope::assertClient($request->user(), $client);
        }

        return view('ops.return_new', [
            'client' => $client,
            'clients' => Client::visibleTo(Client::query())
                ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            // ⚠️ مجمّعة بالصنف — المكتب بيفكر بالصنف، والتوزيع على
            // سطور الفواتير بيحصل في الخدمة وقت الحفظ.
            'lines' => $client ? Returns::returnable($client) : [],
            'policies' => $client ? $client->returnPolicies() : [],
            // ⚠️ **مفلترين بالفريق** — تسكين مرتجع على مندوب مدير
            // تاني بيحط بضاعة في عهدة مالهاش علاقة، و`Scope::assertRep`
            // في `store()` بيرفضها بعد ما المستخدم يكون ملا الفورم.
            'reps' => User::fieldVisibleTo(
                User::whereIn('role', User::FIELD_ROLES), $request->user())
                ->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'policy' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'condition' => ['nullable', 'array'],
            'condition.*' => ['nullable', 'in:good,damaged'],
            // مرتجع اتسلّمه مندوب فعلاً — اختياري
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        Scope::assertClient($request->user(), $client);

        $rep = null;

        if (! empty($data['user_id'])) {
            $rep = User::find($data['user_id']);
            Scope::assertRep($request->user(), $rep, $client);
        }

        $items = [];

        foreach ($data['qty'] as $productId => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $productId,
                'qty' => (int) $qty,
                'condition' => $data['condition'][$productId] ?? ClientReturn::CONDITION_GOOD,
            ];
        }

        try {
            $doc = Returns::create(
                client: $client,
                items: $items,
                policy: $data['policy'],
                rep: $rep,
                visit: null,
                note: $data['note'] ?? null,
                idemKey: null,
                actor: $request->user(),
                source: 'erp',
            );
        } catch (Rejected $e) {
            return back()->withInput()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('ops.returns.show', $doc)
            ->with('ok', __('field.return_saved', ['number' => $doc->number]));
    }

    public function show(Request $request, ClientReturn $return)
    {
        Scope::assertClient($request->user(), $return->client);

        $return->load(['items.product', 'items.invoiceItem.invoice', 'client', 'rep', 'entry']);

        return view('ops.return_doc', ['r' => $return]);
    }

    /**
     * ═══ مسح مرتجع غلط (١٩ أغسطس ٢٠٢٦) — أدمن بس ═══
     *
     * عكس كامل جوه ترانزاكشن واحدة:
     *   • البضاعة اللي دخلت العهدة بالمرتجع بتتسحب منها تاني —
     *     السليم من `returned_in` والتالف من `damaged_in`، من نفس
     *     صف العهدة اللي `intoCustody` كتب فيه بالحرف.
     *   • قيدا الكشف (return + refund لو كاش) بيتمسحوا،
     *     و`recalculate()` بيظبط الرصيد.
     *
     * ⚠️ الحراس:
     *   • عهدة المرتجع اتقفلت/اتصفّت → ممنوع — المحضر اتوقع على
     *     أرقام فيها المرتجع ده.
     *   • البضاعة الراجعة اتصرفت من العهدة بعد كده (الأرقام مش
     *     مكفية للسحب) → ممنوع — مفيش مسح بيطلّع عهدة بالسالب.
     *   • مرتجع الويب (من غير عهدة خالص) بيتعكس قيوده بس.
     */
    public function destroy(Request $request, ClientReturn $return)
    {
        $client = $return->client;
        $custody = $return->custody_id !== null
            ? \App\Models\Custody::find($return->custody_id)
            : null;

        // ⚠️ «مقفولة» = `status === 'closed'` بالحرف — المفتوحة ممكن
        // تكون null (نفس منطق `currentCustody()` في User).
        if ($custody !== null && (string) $custody->status === 'closed') {
            return back()->withErrors(['delete' => __('ops.del_ret_custody_closed')]);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($return, $client, $custody) {
                if ($custody !== null) {
                    // نفس مفتاح الصف اللي intoCustody كتب فيه بالحرف
                    $byProduct = [];

                    foreach ($return->items as $it) {
                        $key = (int) $it->product_id;
                        $byProduct[$key][$it->condition] = ($byProduct[$key][$it->condition] ?? 0) + (int) $it->qty;
                    }

                    foreach ($byProduct as $productId => $cond) {
                        $row = \App\Models\CustodyItem::where([
                            'custody_id' => $custody->id,
                            'product_id' => $productId,
                            'batch_id' => null,
                            'source' => 'custody',
                            'source_ref_id' => 0,
                        ])->lockForUpdate()->first();

                        $good = (int) ($cond[ClientReturn::CONDITION_GOOD] ?? 0);
                        $dmg = (int) ($cond[ClientReturn::CONDITION_DAMAGED] ?? 0);

                        if ($row === null
                            || (int) $row->returned_in < $good
                            || (int) $row->damaged_in < $dmg) {
                            // البضاعة الراجعة اتحركت بعد كده — مفيش
                            // مسح بيطلّع عهدة بالسالب في صمت.
                            throw new Rejected(__('ops.del_ret_moved'));
                        }

                        if ($good > 0) {
                            $row->decrement('returned_in', $good);
                        }

                        if ($dmg > 0) {
                            $row->decrement('damaged_in', $dmg);
                        }
                    }
                }

                \App\Models\Transaction::where('source_type', ClientReturn::class)
                    ->where('source_id', $return->id)
                    ->delete();

                $return->items()->delete();
                $return->delete();

                $client->recalculate();
            });
        } catch (Rejected $e) {
            return back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->route('ops.returns')
            ->with('ok', __('ops.return_deleted', ['number' => $return->number]));
    }
}
