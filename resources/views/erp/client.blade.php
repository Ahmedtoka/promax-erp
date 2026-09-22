@extends('layouts.system')

@section('title', $c->displayName())

@php
    use App\Models\Client;
    use App\Services\Pricing;
    use Illuminate\Support\Str;

    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->isManager();

    // ⚠️ **التحصيل والرصيد الافتتاحي بوابة تانية.** الراوتس بتسمح
    // للمحاسب، بس `$manager` بترجّع له false — فالزرار كان مخبّي عنه
    // والمودال نفسه مش مترسم، يعني الرول اللي اتعمل عشان يحصّل
    // مايقدرش يحصّل.
    $money = auth()->user()->canWorkMoney() || auth()->user()->isBranchManager();
    // $ct = العقد الفعّال (ممكن يكون موروث من السلسلة) — للعرض.
    // $own = عقد العميل نفسه — للتعديل.
    // ⚠️ **الفورم لازم يشتغل على `$own` مش `$ct`.** لو اتعبّى من عقد
    // السلسلة، أول حفظ لفرع Circle K كان بيعمل عقد خاص فاضي (خصم صفر،
    // حجز ضمان صفر) بيحجب عقد السلسلة، والفرع بيفقد الـ30% والـ25%
    // بتوعه في صمت — لمجرد إن حد صلّح رقم تليفون.
    $hasContract = $own !== null;
    $inherited = $own === null && $ct !== null;

    // تلوين الهامش: فوق 25% أخضر، من 10 لـ 25 برتقالي، أقل من 10 أحمر
    $mgCls = fn ($m) => $m > 0.25 ? 'pos' : ($m >= 0.10 ? 'mid' : 'neg');

    // لو سلسلة العميل أو مندوبه موقوف، بنضيفه للقايمة عشان التعديل مايصفّرهوش
    $groupOpts = $c->group && ! $groups->contains('id', $c->group_id)
        ? $groups->concat([$c->group]) : $groups;
    $repOpts = $c->rep && ! $reps->contains('id', $c->rep_id)
        ? $reps->concat([$c->rep]) : $reps;

    // كروت الملخّص بتفلتر كشف الحساب تحت بنوع القيد، بنفس الفترة (٢٢/٩)
    $stUrl = fn (?string $kind) => route('erp.clients.show', ['client' => $c] + $range->query()
        + array_filter(['kind' => $kind])).'#statement';
    // معادلة كارت الرصيد (٢٢/٩): من نفس `$balanceBreak` بتاع التفسير — مبيعات − مرتجعات − تحصيل ± الباقي
    $bkNet = fn (string $k) => (float) ($balanceBreak->firstWhere('kind', $k)?->d ?? 0) - (float) ($balanceBreak->firstWhere('kind', $k)?->c ?? 0);
    $eqSales = $bkNet('sale');
    $eqReturns = -$bkNet('return');
    $eqColl = -$bkNet('collection');
    $eqTotal = (float) $balanceBreak->sum('d') - (float) $balanceBreak->sum('c');
    $eqOther = round($eqTotal) - round($eqSales) + round($eqReturns) + round($eqColl);   // من الأرقام المقرّبة المعروضة — المعادلة تقفل بالجنيه
    $saleN = (int) ($balanceBreak->firstWhere('kind', 'sale')?->n ?? 0);
    // القيد ← مستنده الأصلي. الزيارة مالهاش صفحة، فبتفتح لوحة الزيارات على يومها وعميلها
    $srcUrl = function ($t) use ($c) {
        if (! $t->source_id) {
            return null;
        }

        return match ($t->source_type) {
            \App\Models\Invoice::class => route('ops.invoice', $t->source_id),
            \App\Models\PurchaseOrder::class => route('ops.pos.show', $t->source_id),
            \App\Models\ClientReturn::class => route('ops.returns.show', $t->source_id),
            \App\Models\Visit::class => route('ops.visits', ['q' => $c->code,
                'from' => $t->date->toDateString(), 'to' => $t->date->toDateString()]),
            \App\Models\User::class => route('ops.rep', $t->source_id),
            default => null,
        };
    };
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.clients') }}">← {{ __('client.all_clients') }}</a>
    @if ($manager)
        {{-- ⚠️ **الويزارد مش مودال.** المودال القديم كان بيعرض نص
             الحقول بترتيب مختلف عن شاشة الإنشاء — واللي بيدخل الداتا
             بيتعلّم مكان الخانة بعينه، فكان بيدوّر على العقد في مكان
             وبيلاقيه في مكان تاني. دلوقتي الشاشتين واحدة بالحرف. --}}
        <a class="btn" href="{{ route('erp.clients.edit', $c) }}">✎ {{ __('common.edit') }}</a>
        {{-- عضو سلسلة؟ الزرار بيتسمى باسم اللي المالك بيدوّر عليه:
             «فرع بنفس شروط السلسلة» (١٢/٨) — نفس الاستنساخ بالظبط --}}
        <a class="btn" href="{{ route('erp.clients.clone', $c) }}">⧉ {{ $c->group_id ? __('client.new_branch_like_chain') : __('client.clone_client') }}</a>
    @endif
    @if (auth()->user()?->role === 'admin')
        {{-- تنضيف الزيارات الفاضية — اللي بتقف في وش مسح العميل.
             اللي عليها فلوس أو مستند بتتساب زي ما هي. --}}
        <form method="POST" action="{{ route('erp.clients.visits.purge', $c) }}" style="display:inline"
              onsubmit="return confirm(@js(__('client.purge_visits_confirm', ['name' => $c->displayName()])))">
            @csrf
            <button class="btn sm" type="submit">🧹 {{ __('client.purge_visits') }}</button>
        </form>
        {{-- مسح نهائي — للعميل اللي نزل غلط ومحصلش عليه أي حركة.
             السيرفر بيرفض لو فيه أي نشاط ويقول فيه إيه بالظبط. --}}
        <form method="POST" action="{{ route('erp.clients.destroy', $c) }}" style="display:inline"
              onsubmit="return confirm(@js(__('client.delete_confirm', ['name' => $c->displayName()])))">
            @csrf
            @method('DELETE')
            <button class="btn sm" type="submit" style="color:var(--red);border-color:var(--red)">🗑 {{ __('client.delete_client') }}</button>
        </form>
    @endif
    @if ($money)
        <button class="btn" onclick="openDlg('dlgOpening')">{{ __('client.opening_balance') }}</button>
        <button class="btn green" onclick="openDlg('dlgCollect')">+ {{ __('enums.transaction.collection') }}</button>
    @endif
@endsection

@section('content')

<div style="margin-bottom:14px;display:flex;gap:6px;flex-wrap:wrap">
    <span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span>
    <span class="badge b-blue">💳 {{ __('client.billed_on') }}: {{ $c->priceListLabel() }}</span>
    <span class="badge b-gold">{{ __('client.discount') }} {{ number_format($c->effectiveDiscount() * 100, 1) }}% — {{ $c->discountSource() }}</span>
    {{-- ⚠️ **الشارة دي بتقول للمندوب والمحاسب نفس الحقيقة.** الأبلكيشن
         بيقفل الآجل على عميل الكاش، والمحاسب بيحسب التأخير من نفس
         المدة — فلو الاتنين قروا من مكانين، حد فيهم هيتفاجئ. --}}
    <span class="badge {{ ['cash' => 'b-green', 'credit' => 'b-orange', 'both' => 'b-purple'][$c->paymentTerms()] ?? 'b-gray' }}">
        💵 {{ $c->paymentTermsLabel() }}
        @if ($c->allowsCredit())
            @if ($c->paymentDays() !== null)
                — {{ __('client.pay_days') }} {{ $c->paymentDays() }} ({{ $c->paymentBasisLabel() }})
                • {{ __($c->paymentSourceKey()) }}
            @else
                — {{ __('client.pay_not_set') }}
            @endif
        @endif
    </span>
    @if ($ct)
        <a class="badge {{ $ct->statusClass() }}" href="{{ route('erp.contracts.show', $ct) }}">📜 {{ __('client.contract') }} {{ $ct->number }} — {{ $ct->statusLabel() }}</a>
    @else
        <span class="badge b-red">🚫 {{ __('client.no_contract') }}</span>
    @endif
    @if ($c->group)
        <a class="badge b-purple" href="{{ route('erp.groups.show', $c->group) }}">
            🏬 {{ $c->group->displayName() }} ←
        </a>
    @endif
    @if ($c->channel)
        <a class="badge {{ $c->channel->badgeClass() }}" href="{{ route('erp.clients', ['channel' => $c->channel_id]) }}">🎯 {{ $c->channel->displayName() }}</a>
        @if ($c->sub_channel)<span class="badge b-gray">{{ $c->subChannelLabel() }}</span>@endif
    @endif
    @if ($c->governorate)<span class="badge b-gray">🗺️ {{ $c->governorateLabel() }}</span>@endif
    @if ($c->zone)<a class="badge b-blue" href="{{ route('erp.clients', ['zone' => $c->zone_id]) }}">📍 {{ $c->zone->displayName() }}</a>@endif
    @if ($c->mapUrl())
        {{-- ⚠️ `noopener` — الصفحة اللي بتتفتح بتقدر تتحكم في اللي قبلها
             من غيرها، والرابط ده مكتوب بإيد المندوب مش مضمون. --}}
        <a class="badge b-green" href="{{ $c->mapUrl() }}" target="_blank" rel="noopener noreferrer">🧭 {{ __('geo.location_url') }} ←</a>
    @endif
    @if ($c->taxable)
        <span class="badge b-orange">🧾 {{ __('client.taxable') }} {{ number_format((float) $c->tax_rate * 100, 1) }}%@if ($c->tax_cycle) · {{ $c->taxCycleLabel() }}@endif</span>
    @endif
    @if ($c->manager)<a class="badge b-purple" href="{{ route('ops.rep', $c->manager) }}">{{ __('client.account_manager') }}: {{ $c->manager->displayName() }}</a>@endif
    @if ($c->rep)<a class="badge b-gray" href="{{ route('ops.rep', $c->rep) }}">{{ __('ops.rep') }}: {{ $c->rep->displayName() }}</a>@endif
    @if ($c->is_new)<span class="badge b-purple">{{ __('client.new_from_app') }}</span>@endif
    <span class="badge b-gray">{{ $c->code }}</span>
</div>

<div class="kpis">
    {{-- كل رقم هنا بيتداس (٢٢/٩): فلتر كشف الحساب بنوع القيد، أو تفسير للرقم --}}
    <a @class(['kpi', 'on' => $kind === 'sale']) href="{{ $stUrl($kind === 'sale' ? null : 'sale') }}"><div class="lbl">{{ __('client.purchases') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($c->purchases) }}</div><div class="sub2">{{ __('uia.eq_purchases', ['n' => $fmt($saleN), 'date' => $c->first_activity_at?->format('Y-m-d') ?? '—']) }}</div></a>
    <a @class(['kpi', 'on' => $kind === 'collection']) href="{{ $stUrl($kind === 'collection' ? null : 'collection') }}"><div class="lbl">{{ __('client.collected') }}</div><div class="val pos">{{ $fmt($c->collections) }}</div><div class="sub2">{{ __('uia.eq_coll_rate') }} <span dir="ltr">{{ number_format($c->collectionRate() * 100, 1) }}% = {{ $fmt($c->collections) }} ÷ {{ $fmt($c->purchases) }}</span></div></a>
    <div class="kpi" data-explain onclick="openDlg('dlgBalance')"><div class="lbl">{{ __('client.balance') }}</div><div class="val {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</div><div class="sub2">{{ $c->balance > 0 ? __('client.owes_us') : __('client.in_credit') }} — {{ __('uia.eq_balance', ['s' => $fmt($eqSales), 'r' => $fmt($eqReturns), 'c' => $fmt($eqColl)]) }}@if (abs($eqOther) >= 0.5) {{ __($eqOther > 0 ? 'uia.eq_other_plus' : 'uia.eq_other_minus', ['o' => $fmt(abs($eqOther))]) }}@endif</div></div>
    {{-- ⚠️ المتأخر **غير** الرصيد وغير الأعمار: ده اللي عدّى ميعاد
         سداده حسب شروط العقد. عميل بشروط 60 يوم وعليه فاتورة عمرها
         45 يوم رصيده كبير وأعماره ظاهرة — بس متأخره صفر. --}}
    <div class="kpi" data-explain onclick="openDlg('dlgOverdue')">
        <div class="lbl">{{ __('client.overdue') }}</div>
        @if (! $overdue['has_terms'])
            <div class="val" style="font-size:17px;color:var(--muted)">—</div>
            <div class="sub2">{{ __('client.no_payment_terms') }}</div>
        @elseif ($overdue['amount'] > 0)
            <div class="val neg">{{ $fmt($overdue['amount']) }}</div>
            <div class="sub2">{{ __('client.overdue_by_days', ['days' => $overdue['days']]) }} — {{ __('uia.eq_overdue', ['o' => $fmt($overdue['amount']), 'b' => $fmt($c->balance), 'rest' => $fmt(max((float) $c->balance - $overdue['amount'], 0)), 'days' => $c->paymentDays()]) }}</div>
        @else
            <div class="val pos">0</div>
            <div class="sub2">
                {{ $overdue['due_on']
                    ? __('client.due_on').' '.$overdue['due_on']->format('Y-m-d')
                    : __('client.within_terms') }}
                — {{ __('uia.eq_overdue_none', ['b' => $fmt(max((float) $c->balance, 0)), 'days' => $c->paymentDays()]) }}
            </div>
        @endif
    </div>
    <a @class(['kpi', 'on' => $kind === 'return']) href="{{ $stUrl($kind === 'return' ? null : 'return') }}"><div class="lbl">{{ __('client.returns') }}</div><div class="val mid">{{ $fmt($c->returns) }}</div><div class="sub2">{{ __('uia.eq_return_rate') }} <span dir="ltr">{{ number_format((float) $c->purchases > 0 ? (float) $c->returns / (float) $c->purchases * 100 : 0, 1) }}% = {{ $fmt($c->returns) }} ÷ {{ $fmt($c->purchases) }}</span> · {{ __('client.discounts') }} {{ $fmt($c->rebates) }}</div></a>
    <a class="kpi" href="{{ $stUrl('collection') }}"><div class="lbl">{{ __('client.last_payment') }}</div><div class="val" style="font-size:17px" dir="ltr">{{ $c->last_payment_at?->format('Y-m-d') ?? '—' }}</div><div class="sub2">{{ __('client.last_activity') }} {{ $c->last_activity_at?->format('Y-m-d') ?? '—' }}</div></a>
    @if ($c->phone)
        <a class="kpi" href="tel:{{ $c->phone }}"><div class="lbl">{{ __('common.phone') }}</div><div class="val" style="font-size:17px" dir="ltr">{{ $c->phone }}</div><div class="sub2">{{ $c->address ?: '—' }}</div></a>
    @else
        <div class="kpi"><div class="lbl">{{ __('common.phone') }}</div><div class="val" style="font-size:17px">—</div><div class="sub2">{{ $c->address ?: '—' }}</div></div>
    @endif
</div>

@if ($c->contactList())
<div class="card">
    <h3>📇 {{ __('client.contacts') }}</h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('client.contact_name') }}</th><th>{{ __('client.contact_role') }}</th><th>{{ __('common.phone') }}</th></tr>
            @foreach ($c->contactList() as $ctc)
                <tr>
                    <td><b>{{ $ctc['name'] ?: '—' }}</b></td>
                    <td style="color:var(--muted)">{{ $ctc['role'] ?? '—' }}</td>
                    {{-- ⚠️ `tel:` مش نص عادي — المدير بيفتح الكارت من
                         تليفونه وبيدوس يتصل، مايقعدش ينسخ الرقم. --}}
                    <td dir="ltr">
                        @if ($ctc['phone'])
                            <a href="tel:{{ $ctc['phone'] }}">{{ $ctc['phone'] }}</a>
                        @else — @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

{{-- ═══ ترتيب الصفحة (٢٢/٩): اللي بيطارد فلوس محتاج الأعمار والكشف والفواتير الأول،
     وبعدين العقد والتسعير، وفي الآخر الحركة والخريطة والزيارات ═══ --}}
<div class="grid2">
    <div class="card"><h3>{{ __('report.aging') }}</h3><div class="chartbox"><canvas id="chAg"></canvas></div></div>
    <div class="card"><h3>{{ __('client.monthly_movement') }}</h3><div class="chartbox"><canvas id="chM"></canvas></div></div>
</div>

<div class="card" id="statement">
    <h3>📋 {{ __('client.statement') }} <span class="side">{{ __('client.transaction_countable', ['count' => $txns->total()]) }}</span></h3>
    {{-- ⚠️ فلتر «من — إلى» على `transactions.date` (٩/٩/٢٠٢٦). فورم GET
         منفصل عن مودالات الفلوس — `data-range-filter` عشان
         `ClientFormIntegrityTest` يعرف إن الخانتين دول مش خانات عميل. --}}
    <form method="GET" action="{{ url()->current() }}#statement" class="searchbar" style="margin-bottom:12px" data-noprint data-range-filter>
        {{-- نوع القيد والريفرنس (٢٢/٩): كروت الملخّص فوق بتحط `kind`، والريفرنس بيتداس من الجدول --}}
        <label class="fl"><span>{{ __('client.type') }}</span>
            <select name="kind" onchange="this.form.submit()">
                <option value="">{{ __('ui.all_of', ['x' => __('uia.x_entries')]) }}</option>
                @foreach ($kinds as $k)
                    <option value="{{ $k }}" @selected($kind === $k)>{{ __('enums.transaction.'.$k) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('uia.l_pay_ref') }}</span>
            <input type="text" name="ref" value="{{ $ref }}" dir="ltr" placeholder="{{ __('uia.pay_ref_ph') }}" onchange="this.form.submit()"></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
        @if ($kind !== '' || $ref !== '' || ! $range->isOpen())
            <a class="btn sm" href="{{ url()->current() }}#statement">✕ {{ __('common.clear') }}</a>
        @endif
        <a class="btn sm green" href="{{ route('erp.clients.statement_csv', ['client' => $c] + $range->query()) }}">⬇ {{ __('client.export_statement') }}</a>
    </form>
    <div class="tablewrap" style="max-height:55vh;overflow-y:auto">
        <table>
            {{-- ⚠️ **عمود طريقة التحصيل** (٨/٨/٢٠٢٦). `method` و
                 `reference` وبيانات الشيك بيتخزنوا مع القيد ومحدش
                 بيعرضهم — فالمحاسب بيسجّل شيك برقم وبنك وتاريخ
                 استحقاق، وبعد كده مايلاقيش الرقم ده في أي شاشة
                 ويرجع يفتح الدرج يدوّر على الورقة. --}}
            <thead><tr><th>{{ __('common.date') }}</th><th>{{ __('client.type') }}</th><th>{{ __('client.memo') }}</th><th>{{ __('client.pay_method_label') }}</th><th class="num">{{ __('client.debit') }}</th><th class="num">{{ __('client.credit') }}</th></tr></thead><tbody>
            @foreach ($txns as $t)
                <tr>
                    <td class="num">{{ $t->date->format('Y-m-d') }}</td>
                    <td><span class="badge b-gray">{{ $t->kindLabel() }}</span></td>
                    {{-- البيان بيفتح المستند اللي طلّع القيد: فاتورة / أمر توريد / مرتجع / زيارة التحصيل --}}
                    <td style="white-space:normal;max-width:520px">
                        @if ($u = $srcUrl($t))<a href="{{ $u }}">{{ $t->memo ?: $t->kindLabel() }}</a>@else{{ $t->memo }}@endif
                    </td>
                    <td style="white-space:normal">
                        @if ($t->method)
                            <span class="badge {{ $t->method === 'cash' ? 'b-green' : 'b-blue' }}">
                                {{ $t->methodLabel() }}</span>
                            @if ($t->reference)
                                {{-- الريفرنس ← كل العملاء اللي عليهم قيد بنفس الرقم (تحويل واحد بيتقسّم على كذا فرع) --}}
                                <div style="font-size:10.5px" dir="ltr"><a href="{{ route('erp.clients', ['ref' => $t->reference]) }}" title="{{ __('uia.ref_open_hint') }}">{{ $t->reference }}</a></div>
                            @endif
                            {{-- ⚠️ **تاريخ استحقاق الشيك لازم يبان** —
                                 الشيك بيدخل الحساب فوراً (قرار المالك)،
                                 فالتاريخ ده هو الحاجة الوحيدة اللي
                                 بتفكّر المحاسب إنه لسه مااتحصّلش من البنك. --}}
                            @if ($t->cheque_due)
                                <div style="font-size:10.5px;color:var(--orange)">
                                    {{ $t->cheque_bank }} · {{ __('client.cheque_due') }}
                                    {{ $t->cheque_due->format('Y-m-d') }}
                                </div>
                            @endif
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td class="num">{{ $t->debit > 0 ? $fmt($t->debit) : '—' }}</td>
                    <td class="num pos">{{ $t->credit > 0 ? $fmt($t->credit) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        {{-- الكشف صفحات — الإجمالي من السيرفر على كل القيود المفلترة مش الستين المعروضين --}}
        @if ($txns->total() > 0)
            <tfoot><tr>
                <td colspan="4"><b>Σ {{ __('common.total') }}</b> — {{ __('client.transaction_countable', ['count' => $txns->total()]) }}</td>
                <td class="num"><b>{{ number_format($txnTotals['debit'], 2) }}</b></td>
                <td class="num pos"><b>{{ number_format($txnTotals['credit'], 2) }}</b></td>
            </tr></tfoot>
        @endif
        </table>
    </div>
    @include('partials._pagination', ['p' => $txns])
</div>

@if ($c->invoices->isNotEmpty())
<div class="card">
    <h3>🧾 {{ __('client.app_invoices') }} <span class="side">{{ __('client.invoice_countable', ['count' => $c->invoices->count()]) }}</span></h3>
    {{-- التصدير بيطلّع **كل** الفواتير بتواريخها (الجدول آخر 20 بس) وبنفس فترة «من — إلى» تحت --}}
    <div data-noprint style="margin-bottom:10px">
        <a class="btn sm green" href="{{ route('erp.clients.invoices_csv', ['client' => $c] + $range->query()) }}">⬇ {{ __('client.export_invoices') }}</a>
    </div>
    <div class="tablewrap" style="max-height:55vh;overflow-y:auto">
        <table>
            <thead><tr>
                <th>{{ __('ops.invoice') }}</th><th>{{ __('ops.rep') }}</th><th>{{ __('ops.payment') }}</th>
                <th>{{ __('client.price_list') }}</th><th>{{ __('stock.batch_no') }}</th>
                <th>{{ __('common.subtotal') }}</th><th>{{ __('common.discount') }}</th><th>{{ __('common.total') }}</th><th data-nosum>{{ __('common.date') }}</th>
            </tr></thead><tbody>
            @foreach ($c->invoices->take(20) as $inv)
                @php
                    $batches = $inv->relationLoaded('items')
                        ? $inv->items->map(fn ($it) => $it->batchLabel())->reject(fn ($b) => $b === '—')->unique()->take(3)->implode(__('common.list_separator'))
                        : '';
@endphp
                <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                    <td><a href="{{ route('ops.invoice', $inv) }}" onclick="event.stopPropagation()"><b>{{ $inv->number }}</b></a>
                        @if ($inv->paper_ref)
                            <br><a href="{{ route('ops.invoices', ['paper' => $inv->paper_ref]) }}" onclick="event.stopPropagation()" style="font-size:10.5px" dir="ltr">📄 {{ $inv->paper_ref }}</a>
                        @endif
                    </td>
                    <td>@if ($inv->user)<a href="{{ route('ops.rep', $inv->user) }}" onclick="event.stopPropagation()">{{ $inv->user->displayName() }}</a>@else — @endif</td>
                    <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                    <td><span class="badge b-gray">{{ $inv->priceListLabel() }}</span></td>
                    <td class="num" style="color:var(--muted);font-size:11.5px">{{ $batches !== '' ? $batches : '—' }}</td>
                    <td class="num">{{ $fmt($inv->subtotal) }}</td>
                    <td class="num">{{ $fmt($inv->discount) }}</td>
                    {{-- ⚠️ المستحق مش الصافي — الجدول ده جنب كشف الحساب
                         اللي بيقيّد الإجمالي، ورقمين مختلفين لنفس الفاتورة
                         على نفس الصفحة بيخلّي اليوزر يشك في السيستم كله. --}}
                    <td class="num pos">{{ $fmt($inv->payable()) }}</td>
                    <td class="num">{{ $inv->created_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @endforeach
        </tbody></table>
    </div>
</div>
@endif

{{-- ═══════════════ العقد وبنوده ═══════════════ --}}
<div class="card">
    <h3>📜 {{ __('client.contract_details') }}
        @if ($ct)
            <span class="side">
                {{ $ct->number }}
                @if ($ct->group_id) · {{ __('client.from_chain') }}: {{ $ct->group?->displayName() ?? $ct->displayChain() }}@endif
            </span>
        @endif
    </h3>

    @if ($ct)
        {{-- تنبيهات لازم تبان قبل أي رقم --}}
        @if (! $ct->signed_ok)
            <div class="alert warn">⚠️ {{ __('client.contract_unsigned') }}</div>
        @endif
        @if ($ct->noticeMissed())
            <div class="alert warn">
                ⏰ {{ __('client.notice_missed', ['date' => $ct->noticeDeadline()?->format('Y-m-d')]) }}
            </div>
        @elseif ($ct->noticeDaysLeft() !== null && $ct->noticeDaysLeft() <= 60)
            <div class="alert warn">
                ⏰ {{ __('client.notice_due', [
                    'days' => $ct->noticeDaysLeft(),
                    'date' => $ct->noticeDeadline()?->format('Y-m-d'),
                ]) }}
            </div>
        @endif
        @if ($ct->isConsignment())
            <div class="alert info">📦 {{ __('client.consignment_note') }}</div>
        @endif

        {{-- ═══ الرقم اللي بيتشال على الفاتورة مقابل اللي بيتشال فعلاً ═══ --}}
        <div class="kpis" style="margin-bottom:14px">
            <a class="kpi" href="{{ route('erp.contracts.show', $ct) }}">
                <div class="lbl">{{ __('client.invoice_discount') }}</div>
                <div class="val" style="color:var(--primary)">{{ number_format($ct->discount * 100, 2) }}%</div>
                <div class="sub2">{{ __('client.invoice_discount_hint') }}</div>
            </a>
            <a class="kpi" href="{{ route('erp.contracts.show', $ct) }}">
                <div class="lbl">{{ __('client.total_deduction') }}</div>
                <div class="val {{ $ct->totalDeduction() > 0.3 ? 'neg' : 'mid' }}">
                    {{ number_format($ct->totalDeduction() * 100, 2) }}%
                </div>
                <div class="sub2">
                    @if ($ct->hiddenDeduction() > 0)
                        +{{ number_format($ct->hiddenDeduction() * 100, 2) }}% {{ __('client.after_invoice') }}
                    @else
                        {{ __('client.all_on_invoice') }}
                    @endif
                </div>
            </a>
            <a class="kpi" href="{{ route('erp.contracts.show', $ct) }}">
                <div class="lbl">{{ __('client.annual_commitment') }}</div>
                <div class="val">{{ $fmt($ct->annualCommitment()) }} {{ __('common.currency') }}</div>
                <div class="sub2">{{ __('client.annual_commitment_hint') }}</div>
            </a>
            @if ($ct->withholding_pct > 0)
                <a class="kpi" href="{{ route('erp.contracts.show', $ct) }}">
                    <div class="lbl">{{ __('client.withholding') }}</div>
                    <div class="val neg">{{ number_format($ct->withholding_pct * 100, 2) }}%</div>
                    <div class="sub2">
                        ≈ {{ $fmt($c->balance * $ct->withholding_pct) }} {{ __('common.currency') }}
                    </div>
                </a>
            @endif
            <a class="kpi" href="{{ route('erp.contracts.show', $ct) }}">
                <div class="lbl">{{ __('client.days_to_expiry') }}</div>
                <div class="val {{ $ct->daysLeft() === null ? '' : ($ct->daysLeft() < 0 ? 'neg' : ($ct->daysLeft() <= 90 ? 'mid' : 'pos')) }}">
                    {{ $ct->daysLeft() === null ? '—' : $fmt($ct->daysLeft()) }}
                </div>
                <div class="sub2">{{ $ct->ends_at?->format('Y-m-d') ?? __('client.undated_contract') }}</div>
            </a>
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('client.contract_type') }}</th><th>{{ __('common.status') }}</th>
                    <th>{{ __('client.settlement_mode') }}</th><th>{{ __('client.price_list') }}</th>
                    <th data-nosum>{{ __('client.payment_days') }}</th>
                    <th>{{ __('client.starts_at') }}</th><th>{{ __('client.ends_at') }}</th>
                    <th>{{ __('client.renewal') }}</th>
                </tr>
                <tr>
                    <td><span class="badge b-blue">{{ $ct->typeLabel() ?: '—' }}</span></td>
                    <td><span class="badge {{ $ct->statusClass() }}">{{ $ct->statusLabel() }}</span></td>
                    <td><span class="badge {{ $ct->isConsignment() ? 'b-orange' : 'b-gray' }}">{{ $ct->settlementLabel() }}</span></td>
                    <td>{{ in_array($ct->price_list, Pricing::LISTS, true) ? Pricing::listLabel($ct->price_list) : __('client.inherit_from_client') }}</td>
                    <td class="num">
                        {{ $ct->paymentDays() !== null ? __('client.days_countable', ['count' => $ct->paymentDays()]) : '—' }}
                        @if ($ct->paymentDays() !== null)
                            {{-- ⚠️ الرقم من غير نقطة البداية مالوش معنى — 60 يوم
                                 من أول توريد غير 60 يوم من كل فاتورة. --}}
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('client.from') }} {{ $ct->paymentBasisLabel() }}</span>
                            @php $due = $ct->dueDateFor($c); @endphp
                            @if ($due)
                                <br><span class="badge {{ $due->isPast() ? 'b-red' : 'b-green' }}" style="font-size:10px">
                                    {{ __('client.due_on') }} {{ $due->format('Y-m-d') }}
                                </span>
                            @endif
                        @endif
                    </td>
                    <td class="num">{{ $ct->starts_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $ct->ends_at?->format('Y-m-d') ?? '—' }}</td>
                    <td>
                        @if ($ct->auto_renew)
                            <span class="badge b-orange">{{ __('client.auto_renew') }}</span>
                        @else
                            <span class="badge b-gray">{{ __('client.no_auto_renew') }}</span>
                        @endif
                        @if ($ct->notice_days)
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ __('client.notice_days_n', ['days' => $ct->notice_days]) }}
                            </span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn gold" href="{{ route('erp.contracts.show', $ct) }}">📜 {{ __('client.contract_page') }}</a>
            @if ($ct->file_path)
                <a class="btn" target="_blank" rel="noopener"
                   href="{{ route('erp.contracts.file', $ct) }}">📄 {{ __('client.original_contract') }}</a>
            @endif

        </div>

        {{-- ═══ ملخّص النِسَب — التفاصيل في صفحة العقد ═══ --}}
        @php
            $rates = $ct->contractClauses
                ->filter(fn ($cl) => $cl->pct !== null && (float) $cl->pct > 0)
                ->sortByDesc(fn ($cl) => (float) $cl->pct);
        @endphp

        @if ($rates->count() > 0)
            <div class="tablewrap" style="margin-top:14px">
                <table>
                    <tr>
                        <th>{{ __('client.clause') }}</th>
                        <th class="num" data-nosum>{{ __('client.clause_value') }}</th>
                        <th>{{ __('client.clause_basis') }}</th>
                        <th>{{ __('client.clause_kind') }}</th>
                    </tr>
                    @foreach ($rates as $cl)
                        <tr>
                            <td style="white-space:normal;max-width:420px">
                                {{ $cl->displayLabel() }}
                                @if ($cl->is_uncertain)
                                    <span class="badge b-orange">{{ __('client.clause_uncertain') }}</span>
                                @endif
                                @if ($cl->is_alternative)
                                    <span class="badge b-gray">{{ __('client.clause_alternative') }}</span>
                                @endif
                            </td>
                            <td class="num"><b>{{ $cl->valueLabel() }}</b></td>
                            <td><span class="badge b-gray">{{ $cl->basisLabel() }}</span></td>
                            <td><span class="badge {{ $cl->kindClass() }}">{{ $cl->kindLabel() }}</span></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        {{-- ⚠️ النصوص الحرة والبنود التفصيلية مش هنا عن قصد: محرّرة بالعربي
             في العقد، وعرضها في واجهة إنجليزية بيخلط اللغتين. مكانها صفحة
             العقد اللي بتتعامل مع اللغتين صح. --}}
        <div style="margin-top:14px;text-align:center">
            <a class="btn gold" href="{{ route('erp.contracts.show', $ct) }}">
                📜 {{ __('client.contract_page') }} ({{ $ct->contractClauses->count() }})
            </a>
            @if ($dueCount > 0)
                <a class="btn" href="{{ route('erp.dues', ['client' => $c->id]) }}">
                    💸 {{ __('client.due_amount') }}: {{ $fmt($dueAmount) }} {{ __('common.currency') }}
                </a>
            @endif
        </div>
    @else
        <div class="alert info">
            <span>📜</span>
            <span>
                {{ __('client.without_contract_note') }}<br>
                <b>{{ __('client.applied_discount_now') }}:</b>
                {{ number_format($c->effectiveDiscount() * 100, 1) }}% — {{ $c->discountSource() }}
            </span>
        </div>
    @endif
</div>



<div class="card">
    <h3>🏷️ {{ __('client.applied_pricing') }} <span class="side">{{ __('client.applied_pricing_hint') }}</span></h3>
    <div class="searchbar">
        <input type="text" id="pricedQ" oninput="filterPriced(this.value)" placeholder="🔍 {{ __('stock.search_item') }}">
        <span class="badge b-blue">{{ __('client.billed_on') }}: {{ $c->priceListLabel() }}</span>
        <span class="badge b-gold">{{ __('client.discount') }} {{ number_format($c->effectiveDiscount() * 100, 1) }}% — {{ $c->discountSource() }}</span>
    </div>
    <div class="tablewrap" style="max-height:60vh;overflow-y:auto">
        <table>
            <thead>
                <tr>
                    <th>{{ __('common.code') }}</th><th>{{ __('stock.item') }}</th><th>{{ __('stock.unit') }}</th>
                    <th data-nosum>{{ __('client.list_price') }}</th><th data-nosum>{{ __('client.discount_pct') }}</th>
                    <th data-nosum>{{ __('client.unit_price') }}</th><th data-nosum>{{ __('stock.margin_pct') }}</th>
                </tr>
            </thead>
            <tbody id="pricedRows">
                @forelse ($priced as $row)
                    @php
                        $p = $row['product'];
                        $q = $row['quote'];
@endphp
                    <tr data-txt="{{ Str::lower($p->code.' '.$p->name.' '.$p->name_en) }}">
                        <td class="num">{{ $p->code }}</td>
                        <td><a href="{{ route('erp.products.show', $p) }}"><b>{{ $p->displayName() }}</b></a></td>
                        <td style="color:var(--muted);font-size:11.5px">{{ $p->unitLabel() }}</td>
                        <td class="num">{{ number_format($q['list_price'], 2) }}</td>
                        <td class="num">{{ number_format($q['discount_pct'] * 100, 1) }}%</td>
                        <td class="num" style="color:var(--primary);font-weight:800">{{ number_format($q['unit_price'], 2) }}</td>
                        <td class="num {{ $mgCls($q['margin_pct']) }}"><b>{{ number_format($q['margin_pct'] * 100, 1) }}%</b></td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">{{ __('client.no_priced_products') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ حركة الأصناف بالكمية (٨/٩/٢٠٢٦) — طلب المالك: «الكميات والمنتجات
     وكام عائلة وسحب كام من ده ورجع كام» — الكارت المشترك مع صفحة السلسلة ═══ --}}
@include('partials._movements_table', [
    'movements' => $movements,
    {{-- الفترة المختارة تحت في كشف الحساب بتمشي مع التصدير (٩/٩/٢٠٢٦) --}}
    'exportUrl' => route('erp.clients.movements', ['client' => $c] + $range->query()),
    'title' => __('client.movements_title'),
    'hint' => __('client.movements_hint_client'),
    'range' => $range,
])


<div class="grid2">
    <div class="card"><h3>{{ __('client.sales_by_family') }}</h3><div class="chartbox"><canvas id="chSplit"></canvas></div></div>
    <div class="card">
        <h3>📍 {{ __('client.branch_location') }}</h3>
        <div class="mapbox sm" id="mapClient"></div>
        @if (! $c->hasLocation())
            <div style="font-size:11.5px;color:var(--muted);margin-top:8px">
                {{ __('client.no_coords') }}

            </div>
        @endif
    </div>
</div>

{{-- ═══════════ آخر الزيارات (١٥ أغسطس ٢٠٢٦) ═══════════
     بلاغ المالك: «مش شايف الزيارات اللي اتعملت، ولا صور الرف».
     كارت العميل بيقول دلوقتي: مين جه، امتى، قعد قد إيه، وطلع من
     الزيارة إيه — والصور بتفتح بالحجم الكامل في تاب. --}}
@if ($visits->isNotEmpty())
<div class="card">
    <h3>🚪 {{ __('ops.vb_recent') }}
        <span class="side">{{ __('ops.vb_recent_hint', ['count' => $visits->count()]) }}</span>
        @if (\App\Support\Access::allows(auth()->user(), 'ops.visits'))
            <a class="btn sm" style="float:inline-end"
               href="{{ route('ops.visits', ['q' => $c->code]) }}">{{ __('ops.vb_see_all') }}</a>
        @endif
    </h3>

    <div class="tablewrap">
        <table>
            <thead><tr>
                <th data-nosum>{{ __('common.date') }}</th>
                <th data-nosum>{{ __('ops.vb_in_out') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th data-nosum>{{ __('ops.vb_duration') }}</th>
                <th>{{ __('ops.vb_visit_value') }}</th>
                <th data-nosum>{{ __('ops.vb_outcome') }}</th>
                <th data-nosum>{{ __('field.shelf_photos') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($visits as $v)
                @php
                    $vo = $visitOut[$v->id] ?? \App\Support\VisitOutcomes::blank();
                    $tz = fn ($d) => $d?->copy()->timezone('Africa/Cairo');
                    // ⚠️ **قيمة الزيارة = المباع ناقص المرتجع.** التحصيل
                    // **مش** داخل: هو تسديد لمديونية قديمة مش بيعة جديدة،
                    // وجمعه هنا كان هيعدّ نفس الفلوس مرتين.
                    $vValue = $vo['inv_total'] - $vo['ret_total'];
                @endphp
                <tr>
                    <td class="num s" dir="ltr">
                        {{ $tz($v->checked_in_at)?->format('Y-m-d') ?? '—' }}
                    </td>
                    <td class="num s" dir="ltr">
                        {{ $tz($v->checked_in_at)?->format('h:i A') ?? '—' }}
                        → {{ $tz($v->checked_out_at)?->format('h:i A') ?? '—' }}
                    </td>
                    <td>@if ($v->user)<a href="{{ route('ops.rep', $v->user) }}">{{ $v->user->displayName() }}</a>@else — @endif</td>
                    <td class="num s">
                        {{ $v->minutes() !== null ? __('ops.minutes', ['count' => $v->minutes()]) : __('ops.in_progress') }}
                    </td>
                    <td class="num {{ $vValue > 0 ? 'pos' : '' }}">
                        {{ $vValue != 0 ? number_format($vValue, 2) : '—' }}
                    </td>
                    {{-- ⚠️ **البارشال المشترك مش نسخة محلية** (١٥/٨):
                         الشارات كانت متكرّرة هنا بالحرف، فأي تحسين في
                         `_visit_outcome` ماكانش بيوصل الشاشة دي —
                         وده اللي خلّى «📦 1» تفضل بلا لابل هنا. --}}
                    <td style="white-space:normal">
                        @include('partials._visit_outcome', ['o' => $vo, 'thumbs' => false])
                    </td>
                    <td style="white-space:normal;min-width:150px">
                        @if ($vo['photo_count'] === 0)
                            <span style="color:var(--muted)">—</span>
                        @else
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                @foreach ($vo['photos'] as $p)
                                    <a href="{{ $p->url() }}" target="_blank" rel="noopener"
                                       title="{{ $p->stage === 'before' ? __('field.shelf_before') : __('field.shelf_after') }}">
                                        <img src="{{ $p->url() }}" alt="" loading="lazy"
                                             style="width:52px;height:52px;object-fit:cover;border-radius:8px;border:2px solid {{ $p->stage === 'before' ? 'var(--orange)' : 'var(--green)' }}">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif


{{-- ═══ تفسير رقم الرصيد والمتأخر (٢٢/٩) ═══ --}}
<dialog id="dlgBalance">
    <div class="dlg">
        <h4>{{ __('uia.balance_explain') }} — {{ $c->displayName() }}</h4>
        <div class="tablewrap">
            <table data-noxl>
                <thead><tr><th>{{ __('client.type') }}</th><th class="num" data-nosum>{{ __('uia.entries_n') }}</th><th class="num">{{ __('client.debit') }}</th><th class="num">{{ __('client.credit') }}</th><th class="num">{{ __('uia.net_effect') }}</th></tr></thead>
                <tbody>
                @foreach ($balanceBreak as $bk)
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', ['client' => $c, 'kind' => $bk->kind]) }}#statement'">
                        <td><span class="badge b-gray">{{ __('enums.transaction.'.$bk->kind) }}</span></td>
                        <td class="num">{{ $fmt($bk->n) }}</td>
                        <td class="num">{{ number_format((float) $bk->d, 2) }}</td>
                        <td class="num pos">{{ number_format((float) $bk->c, 2) }}</td>
                        <td class="num"><b>{{ number_format((float) $bk->d - (float) $bk->c, 2) }}</b></td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <td colspan="2"><b>{{ __('client.balance') }}</b></td>
                    <td class="num">{{ number_format((float) $balanceBreak->sum('d'), 2) }}</td>
                    <td class="num">{{ number_format((float) $balanceBreak->sum('c'), 2) }}</td>
                    <td class="num"><b>{{ number_format((float) $balanceBreak->sum('d') - (float) $balanceBreak->sum('c'), 2) }}</b></td>
                </tr></tfoot>
            </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgBalance')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

<dialog id="dlgOverdue">
    <div class="dlg">
        <h4>{{ __('uia.overdue_explain') }} — {{ $c->displayName() }}</h4>
        <div class="tablewrap">
            <table data-noxl>
                <tr><th>{{ __('uia.item') }}</th><th class="num" data-nosum>{{ __('uia.value') }}</th></tr>
                <tr><td>{{ __('client.payment_days') }}</td><td class="num">{{ $c->paymentDays() !== null ? __('client.days_countable', ['count' => $c->paymentDays()]).' — '.$c->paymentBasisLabel() : __('client.no_payment_terms') }}</td></tr>
                <tr><td>{{ __('client.due_on') }}</td><td class="num" dir="ltr">{{ $overdue['due_on']?->format('Y-m-d') ?? '—' }}</td></tr>
                <tr><td>{{ __('client.overdue') }}</td><td class="num neg"><b>{{ number_format((float) $overdue['amount'], 2) }}</b></td></tr>
                <tr><td>{{ __('uia.overdue_days') }}</td><td class="num">{{ (int) ($overdue['days'] ?? 0) }}</td></tr>
                <tr><td>{{ __('client.balance') }}</td><td class="num">{{ number_format((float) $c->balance, 2) }}</td></tr>
            </table>
        </div>
        <h4 style="margin-top:14px">{{ __('report.aging') }}</h4>
        <div class="tablewrap">
            <table data-noxl>
                <tr><th>≤30</th><th>31-60</th><th>61-90</th><th>91-180</th><th>{{ __('report.days_180_plus') }}</th></tr>
                <tr>
                    @foreach (['a30', 'a60', 'a90', 'a180', 'a180p'] as $ak)
                        <td class="num">{{ $fmt($aging[$ak]) }}</td>
                    @endforeach
                </tr>
            </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgOverdue')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- ⚠️ **المودالين دول تحت بوابة الفلوس مش بوابة المدير.** كانوا جوه
     `@if ($manager)` — يعني المحاسب مش بس مشفش الزرار، المودال نفسه
     ماكانش بيترسم في الصفحة، فحتى لو ناداه بجافاسكربت مافيش حاجة تتفتح. --}}
@if ($money)
<dialog id="dlgOpening">
    @php
    // ⚠️ استعلام مباشر مش `$c->transactions` — العلاقة دي بتجيب
        // كل حركات العميل (ممكن آلاف) عشان نقرا صف واحد.
        $opening = $c->transactions()->where('kind', 'opening')->first();
    @endphp
    <form class="dlg" method="POST" action="{{ route('erp.clients.opening', $c) }}">
        @csrf
        <h4>{{ __('client.opening_balance') }} — {{ $c->displayName() }}</h4>

        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('client.opening_hint') }}</span>
        </div>

        @if ($opening)
            <div class="alert warn" style="margin-bottom:12px">
                <span>♻️</span>
                <span>{{ __('client.opening_replaces', [
                    'amount' => number_format((float) $opening->debit - (float) $opening->credit, 2),
                    'date' => $opening->date->format('Y-m-d'),
                ]) }}</span>
            </div>
        @endif

        <div class="frow">
            <div>
                <label class="f">{{ __('common.amount') }}</label>
                <input type="number" step="0.01" name="amount" required style="width:100%"
                       value="{{ $opening ? (float) $opening->debit - (float) $opening->credit : '' }}">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.opening_negative_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('common.date') }}</label>
                <input type="date" name="date" required max="{{ today()->toDateString() }}" style="width:100%"
                       value="{{ $opening?->date?->toDateString() ?? today()->toDateString() }}">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.opening_date_hint') }}</div>
            </div>
        </div>
        <div><label class="f">{{ __('client.memo') }}</label><input type="text" name="memo" maxlength="190" style="width:100%" value="{{ $opening?->memo }}"></div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgOpening')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgCollect">
    {{-- ⚠️ `enctype` لازم عشان صورة الإثبات (٩/٩) — من غيره الملف بيوصل null في صمت --}}
    <form class="dlg" method="POST" action="{{ route('erp.clients.collect', $c) }}" enctype="multipart/form-data">
        @csrf
        <h4>{{ __('client.record_collection_from', ['client' => $c->displayName()]) }}</h4>
        <div class="frow">
            <div><label class="f">{{ __('common.amount') }}</label><input type="number" step="0.01" name="amount" required style="width:100%"></div>
            <div><label class="f">{{ __('common.date') }}</label><input type="date" name="date" value="{{ today()->toDateString() }}" style="width:100%"></div>
        </div>
        {{-- ═══ طريقة التحصيل (قرار المالك ٨/٨/٢٠٢٦) ═══
             ⚠️ **التحصيل كان رقم بلا طريقة** — المحاسب بيقفل اليومية
             ومعاه إجمالي واحد من غير ما يعرف كام كاش في الخزنة وكام
             شيك في الدرج وكام تحويل لازم يتطابق مع كشف البنك.
             ⚠️ **الشيك بيدخل الحساب فوراً زي الكاش** (قرار المالك). --}}
        <div class="frow">
            <div>
                <label class="f">{{ __('client.pay_method_label') }} <b class="req-star">*</b></label>
                {{-- ⚠️ **اختيار فاضي أول القايمة.** الخانة دي اتعملت
                     أصلاً (٨/٨/٢٠٢٦) عشان «التحصيل كان رقم بلا طريقة»،
                     و«نقدي» مختارة سلفاً كانت بترجّع نفس المشكلة: اللي
                     بيحصّل شيك بيعدّي على الخانة من غير ما يقراها
                     والقيد بيتكتب كاش. `required` في الشاشة و
                     `required` في `OpsController::collect` — فالفاضي
                     بيتلقف في الحالتين. --}}
                <select name="method" id="collMethod" required style="width:100%"
                        onchange="collMethodChanged()">
                    <option value="">— {{ __('client.pick_pay_method') }} —</option>
                    @foreach (\App\Models\Transaction::METHODS as $m)
                        <option value="{{ $m }}">{{ __('client.pay_method_'.$m) }}</option>
                    @endforeach
                </select>
            </div>
            <div id="collRefBox" style="display:none">
                <label class="f">{{ __('client.pay_reference') }} <b class="req-star">*</b></label>
                <input type="text" name="reference" maxlength="100" style="width:100%"
                       placeholder="{{ __('client.pay_reference_ph') }}">
            </div>
        </div>
        <div class="frow collChequeBox" style="display:none">
            <div>
                <label class="f">{{ __('client.cheque_bank') }} <b class="req-star">*</b></label>
                <input type="text" name="cheque_bank" maxlength="120" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.cheque_due') }} <b class="req-star">*</b></label>
                <input type="date" name="cheque_due" style="width:100%">
            </div>
        </div>

        {{-- ⭐ إثبات التحويل/الشيك + ضرايب مخصومة تحت الحساب (٩/٩/٢٠٢٦) — تحصيل مباشر من العميل بلا مندوب --}}
        <div class="frow">
            <div>
                <label class="f">{{ __('client.collect_proof') }}</label>
                <input type="file" name="proof" accept="image/*" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.tax_withheld') }}</label>
                <input type="number" name="tax_withheld" step="0.01" min="0" value="0" style="width:100%">
            </div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin:-4px 0 8px">{{ __('client.tax_withheld_hint') }}</div>
        <div><label class="f">{{ __('client.memo') }}</label><input type="text" name="memo" placeholder="{{ __('client.cash_collection') }}" style="width:100%"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgCollect')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('common.record') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
{{-- ⚠️ الكتلة دي لازم تفضل جوه سكشن السكربتس. لو اتحطت براها، البليد
     بيطبع محتوى الفيو الابن قبل الليّاوت، فالوسم بيخرج قبل الدوكتايب
     والصفحة بتدخل quirks mode. --}}

@php
    $splitLabels = array_map(fn ($s) => $s['label'], $split);
    $splitValues = array_map(fn ($s) => round($s['amt']), $split);
    $agLabels = ['≤30', '31-60', '61-90', '91-180', __('report.days_180_plus')];
    $agValues = [round($aging['a30']), round($aging['a60']), round($aging['a90']), round($aging['a180']), round($aging['a180p'])];
    $mLabels = $monthly->pluck('m')->all();
    $mSales = $monthly->map(fn ($r) => round((float) $r->sales))->all();
    $mColl = $monthly->map(fn ($r) => round((float) $r->coll))->all();
    $noSplitMsg = __('client.no_app_invoices');
    // ممنوع نستخدم دايركتيف الـ json بمصفوفة جوه الـ Blade — بيكسّر الـ parser.
    $clauseStrings = json_encode([
        'placeholder' => __('client.contract_clause'),
        'remove' => __('client.remove_clause'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
<script>
const CLAUSE_T = {!! $clauseStrings !!};

// بيظهر أو يخفي بلوك العقد — الحقول تفضل في الفورم عشان تتبعت فاضية

// صف جهة تواصل جديد
// ⚠️ الفهرس من `Date.now()` مش عدّاد. العدّاد بيكرّر الفهرس بعد ما
// المستخدم يمسح صف، و PHP بيدوس على الصف القديم.
function addContactRow(hostId) {
    const host = document.getElementById(hostId);
    if (!host) return;

    const i = Date.now();
    const row = document.createElement('div');
    row.className = 'frow contact-row';
    row.style.marginBottom = '6px';

    // ⚠️ الـplaceholder إنجليزي عن قصد — بيقول الشكل المتوقّع من غير تعليمات
    const cell = (name, ph) =>
        '<div><input type="text" name="contacts[' + i + '][' + name + ']" dir="ltr" '
      + 'maxlength="120" placeholder="' + ph + '" style="width:100%"></div>';

    row.innerHTML = cell('name', 'Mohamed Ahmed') + cell('role', 'Branch Manager')
      + '<div style="display:flex;gap:6px"><input type="text" name="contacts[' + i + '][phone]" dir="ltr" maxlength="30" placeholder="01000000000" style="flex:1;min-width:0">'
      + '<button type="button" class="btn sm red">&times;</button></div>';

    row.querySelector('button').addEventListener('click', function () { row.remove(); });
    host.appendChild(row);
    row.querySelector('input').focus();
}

// بند خصم جاهز — التشيك بوكس بيفتح خانة الرقم
function toggleEdClause(key) {
    const cb = document.getElementById('ed_cl_' + key);
    const box = document.getElementById('ed_box_' + key);
    if (!cb || !box) return;
    box.style.display = cb.checked ? '' : 'none';
    if (cb.checked) { const i = box.querySelector('input[type=number]'); if (i) i.focus(); }
}

// بند جديد — الاسم مصفوفة عادية فمش محتاجين إعادة ترقيم
function addClauseRow(hostId, value) {
    const host = document.getElementById(hostId);
    if (!host) return;

    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px';

    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'contract_clauses[]';
    input.maxLength = 500;
    input.placeholder = CLAUSE_T.placeholder;
    input.style.flex = '1';
    input.style.minWidth = '0';
    if (value) input.value = value;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn sm red';
    del.textContent = CLAUSE_T.remove;
    del.addEventListener('click', function () { row.remove(); });

    row.appendChild(input);
    row.appendChild(del);
    host.appendChild(row);
}

// بحث في جدول التسعيرة المطبّقة
function filterPriced(value) {
    const q = (value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#pricedRows tr');
    for (const tr of rows) {
        const txt = tr.getAttribute('data-txt');
        if (txt === null) continue;
        tr.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
    }
}

promaxMap('mapClient', {!! json_encode($c->hasLocation() ? [[
    'lat' => (float) $c->lat, 'lng' => (float) $c->lng,
    'title' => $c->displayName(), 'subtitle' => $c->address, 'type' => 'client',
]] : [], JSON_UNESCAPED_UNICODE) !!});

const hasSplit = {{ count($split) > 0 ? 'true' : 'false' }};
if (hasSplit) {
  new Chart(document.getElementById('chSplit'), {
    type:'doughnut',
    data:{ labels:{!! json_encode($splitLabels, JSON_UNESCAPED_UNICODE) !!},
           datasets:[{ data:{!! json_encode($splitValues) !!}, backgroundColor:PALETTE, borderColor:'#fff', borderWidth:3 }] },
    options:{ cutout:'58%', plugins:{ legend:{ position:'bottom' } } },
  });
} else {
  document.getElementById('chSplit').closest('.chartbox').innerHTML =
    '<div style="display:grid;place-items:center;height:100%;color:#6B6B66;font-size:13px">' + {!! json_encode($noSplitMsg, JSON_UNESCAPED_UNICODE) !!} + '</div>';
}

new Chart(document.getElementById('chAg'), {
  type:'bar',
  data:{ labels:{!! json_encode($agLabels, JSON_UNESCAPED_UNICODE) !!},
         datasets:[{ label:{!! json_encode(__('report.outstanding'), JSON_UNESCAPED_UNICODE) !!}, data:{!! json_encode($agValues) !!},
         backgroundColor:[BRAND.green,BRAND.blue,BRAND.royal,BRAND.orange,BRAND.red], borderRadius:8, maxBarThickness:56 }] },
  options:{ plugins:{ legend:{ display:false } }, scales:AXES },
});

new Chart(document.getElementById('chM'), {
  type:'bar',
  data:{ labels:{!! json_encode($mLabels) !!},
      datasets:[
        { label:{!! json_encode(__('report.sales'), JSON_UNESCAPED_UNICODE) !!}, data:{!! json_encode($mSales) !!}, backgroundColor:BRAND.royal, borderRadius:6, maxBarThickness:34 },
        { label:{!! json_encode(__('report.collections'), JSON_UNESCAPED_UNICODE) !!}, data:{!! json_encode($mColl) !!}, backgroundColor:BRAND.green, borderRadius:6, maxBarThickness:34 },
      ] },
  options:{ plugins:{ legend:{ position:'bottom' } }, scales:AXES },
});

// ═══ طريقة التحصيل — إظهار الحقول حسب الاختيار (٨/٨/٢٠٢٦) ═══
//
// ⚠️ **العرض راحة، والإجبار على السيرفر.** الفاليديشن في
// `OpsController::collect` هو اللي بيرفض تحصيل غير نقدي بلا ريفرنس —
// الجافاسكربت هنا بيخبّي الخانات اللي مالهاش لازمة بس. حقل مخبّي
// بيتبعت فاضي، والسيرفر بيرفضه، وده المطلوب.
function collMethodChanged() {
  var m = document.getElementById('collMethod');
  if (!m) return;

  var v = m.value;
  var refBox = document.getElementById('collRefBox');
  var chequeBoxes = document.querySelectorAll('.collChequeBox');

  // ⚠️ الفاضي بيتعامل زي الكاش هنا — قبل ما يختار طريقة، طلب رقم
  // مرجعي منه بيلخبطه، والخانة أصلاً `required_unless:method,cash`
  // فمالهاش معنى من غير طريقة.
  if (refBox) refBox.style.display = (v === '' || v === 'cash') ? 'none' : '';

  for (var i = 0; i < chequeBoxes.length; i++) {
    chequeBoxes[i].style.display = (v === 'cheque') ? '' : 'none';
  }
}

collMethodChanged();
</script>

@endsection
