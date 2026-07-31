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
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.clients') }}">← {{ __('client.all_clients') }}</a>
    @if ($manager)
        <button class="btn" onclick="openDlg('dlgEdit')">{{ __('common.edit') }}</button>
        <a class="btn" href="{{ route('erp.clients.clone', $c) }}">⧉ {{ __('client.clone_client') }}</a>
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
    @if ($ct)
        <span class="badge {{ $ct->statusClass() }}">📜 {{ __('client.contract') }} {{ $ct->number }} — {{ $ct->statusLabel() }}</span>
    @else
        <span class="badge b-red">🚫 {{ __('client.no_contract') }}</span>
    @endif
    @if ($c->group)
        <a class="badge b-purple" href="{{ route('erp.groups.show', $c->group) }}">
            🏬 {{ $c->group->displayName() }} ←
        </a>
    @endif
    @if ($c->channel)
        <span class="badge {{ $c->channel->badgeClass() }}">🎯 {{ $c->channel->displayName() }}</span>
        @if ($c->sub_channel)<span class="badge b-gray">{{ $c->subChannelLabel() }}</span>@endif
    @endif
    @if ($c->governorate)<span class="badge b-gray">🗺️ {{ $c->governorateLabel() }}</span>@endif
    @if ($c->zone)<span class="badge b-blue">📍 {{ $c->zone->displayName() }}</span>@endif
    @if ($c->mapUrl())
        {{-- ⚠️ `noopener` — الصفحة اللي بتتفتح بتقدر تتحكم في اللي قبلها
             من غيرها، والرابط ده مكتوب بإيد المندوب مش مضمون. --}}
        <a class="badge b-green" href="{{ $c->mapUrl() }}" target="_blank" rel="noopener noreferrer">🧭 {{ __('geo.location_url') }} ←</a>
    @endif
    @if ($c->taxable)
        <span class="badge b-orange">🧾 {{ __('client.taxable') }} {{ number_format((float) $c->tax_rate * 100, 1) }}%@if ($c->tax_cycle) · {{ $c->taxCycleLabel() }}@endif</span>
    @endif
    @if ($c->manager)<span class="badge b-purple">{{ __('client.account_manager') }}: {{ $c->manager->displayName() }}</span>@endif
    @if ($c->rep)<span class="badge b-gray">{{ __('ops.rep') }}: {{ $c->rep->displayName() }}</span>@endif
    @if ($c->is_new)<span class="badge b-purple">{{ __('client.new_from_app') }}</span>@endif
    <span class="badge b-gray">{{ $c->code }}</span>
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('client.purchases') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($c->purchases) }}</div><div class="sub2">{{ __('client.since', ['date' => $c->first_activity_at?->format('Y-m-d') ?? '—']) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.collected') }}</div><div class="val pos">{{ $fmt($c->collections) }}</div><div class="sub2">{{ number_format($c->collectionRate() * 100, 1) }}% {{ __('client.collection_rate') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.balance') }}</div><div class="val {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</div><div class="sub2">{{ $c->balance > 0 ? __('client.owes_us') : __('client.in_credit') }}</div></div>
    {{-- ⚠️ المتأخر **غير** الرصيد وغير الأعمار: ده اللي عدّى ميعاد
         سداده حسب شروط العقد. عميل بشروط 60 يوم وعليه فاتورة عمرها
         45 يوم رصيده كبير وأعماره ظاهرة — بس متأخره صفر. --}}
    <div class="kpi">
        <div class="lbl">{{ __('client.overdue') }}</div>
        @if (! $overdue['has_terms'])
            <div class="val" style="font-size:17px;color:var(--muted)">—</div>
            <div class="sub2">{{ __('client.no_payment_terms') }}</div>
        @elseif ($overdue['amount'] > 0)
            <div class="val neg">{{ $fmt($overdue['amount']) }}</div>
            <div class="sub2">{{ __('client.overdue_by_days', ['days' => $overdue['days']]) }}</div>
        @else
            <div class="val pos">0</div>
            <div class="sub2">
                {{ $overdue['due_on']
                    ? __('client.due_on').' '.$overdue['due_on']->format('Y-m-d')
                    : __('client.within_terms') }}
            </div>
        @endif
    </div>
    <div class="kpi"><div class="lbl">{{ __('client.returns') }}</div><div class="val mid">{{ $fmt($c->returns) }}</div><div class="sub2">{{ __('client.discounts') }} {{ $fmt($c->rebates) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.last_payment') }}</div><div class="val" style="font-size:17px">{{ $c->last_payment_at?->format('Y-m-d') ?? '—' }}</div><div class="sub2">{{ __('client.last_activity') }} {{ $c->last_activity_at?->format('Y-m-d') ?? '—' }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('common.phone') }}</div><div class="val" style="font-size:17px">{{ $c->phone ?? '—' }}</div><div class="sub2">{{ $c->address ?: '—' }}</div></div>
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
            <div class="kpi">
                <div class="lbl">{{ __('client.invoice_discount') }}</div>
                <div class="val" style="color:var(--primary)">{{ number_format($ct->discount * 100, 2) }}%</div>
                <div class="sub2">{{ __('client.invoice_discount_hint') }}</div>
            </div>
            <div class="kpi">
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
            </div>
            <div class="kpi">
                <div class="lbl">{{ __('client.annual_commitment') }}</div>
                <div class="val">{{ $fmt($ct->annualCommitment()) }} {{ __('common.currency') }}</div>
                <div class="sub2">{{ __('client.annual_commitment_hint') }}</div>
            </div>
            @if ($ct->withholding_pct > 0)
                <div class="kpi">
                    <div class="lbl">{{ __('client.withholding') }}</div>
                    <div class="val neg">{{ number_format($ct->withholding_pct * 100, 2) }}%</div>
                    <div class="sub2">
                        ≈ {{ $fmt($c->balance * $ct->withholding_pct) }} {{ __('common.currency') }}
                    </div>
                </div>
            @endif
            <div class="kpi">
                <div class="lbl">{{ __('client.days_to_expiry') }}</div>
                <div class="val {{ $ct->daysLeft() === null ? '' : ($ct->daysLeft() < 0 ? 'neg' : ($ct->daysLeft() <= 90 ? 'mid' : 'pos')) }}">
                    {{ $ct->daysLeft() === null ? '—' : $fmt($ct->daysLeft()) }}
                </div>
                <div class="sub2">{{ $ct->ends_at?->format('Y-m-d') ?? __('client.undated_contract') }}</div>
            </div>
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('client.contract_type') }}</th><th>{{ __('common.status') }}</th>
                    <th>{{ __('client.settlement_mode') }}</th><th>{{ __('client.price_list') }}</th>
                    <th>{{ __('client.payment_days') }}</th>
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
                        <th class="num">{{ __('client.clause_value') }}</th>
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
    <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('common.code') }}</th><th>{{ __('stock.item') }}</th><th>{{ __('stock.unit') }}</th>
                    <th>{{ __('client.list_price') }}</th><th>{{ __('client.discount_pct') }}</th>
                    <th>{{ __('client.unit_price') }}</th><th>{{ __('stock.margin_pct') }}</th>
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
                        <td><b>{{ $p->displayName() }}</b></td>
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

<div class="grid2">
    <div class="card">
        <h3>📍 {{ __('client.branch_location') }}</h3>
        <div class="mapbox sm" id="mapClient"></div>
        @if (! $c->hasLocation())
            <div style="font-size:11.5px;color:var(--muted);margin-top:8px">
                {{ __('client.no_coords') }}

            </div>
        @endif
    </div>
    <div class="card"><h3>{{ __('report.aging') }}</h3><div class="chartbox"><canvas id="chAg"></canvas></div></div>
</div>

<div class="card"><h3>{{ __('client.sales_by_family') }}</h3><div class="chartbox"><canvas id="chSplit"></canvas></div></div>

<div class="card"><h3>{{ __('client.monthly_movement') }}</h3><div class="chartbox"><canvas id="chM"></canvas></div></div>

@if ($c->invoices->isNotEmpty())
<div class="card">
    <h3>🧾 {{ __('client.app_invoices') }} <span class="side">{{ __('client.invoice_countable', ['count' => $c->invoices->count()]) }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.invoice') }}</th><th>{{ __('ops.rep') }}</th><th>{{ __('ops.payment') }}</th>
                <th>{{ __('client.price_list') }}</th><th>{{ __('stock.batch_no') }}</th>
                <th>{{ __('common.subtotal') }}</th><th>{{ __('common.discount') }}</th><th>{{ __('common.total') }}</th><th>{{ __('common.date') }}</th>
            </tr>
            @foreach ($c->invoices->take(20) as $inv)
                @php
                    $batches = $inv->relationLoaded('items')
                        ? $inv->items->map(fn ($it) => $it->batchLabel())->reject(fn ($b) => $b === '—')->unique()->take(3)->implode(__('common.list_separator'))
                        : '';
@endphp
                <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                    <td><b>{{ $inv->number }}</b></td>
                    <td>{{ $inv->user?->displayName() ?? '—' }}</td>
                    <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                    <td><span class="badge b-gray">{{ $inv->priceListLabel() }}</span></td>
                    <td class="num" style="color:var(--muted);font-size:11.5px">{{ $batches !== '' ? $batches : '—' }}</td>
                    <td class="num">{{ $fmt($inv->subtotal) }}</td>
                    <td class="num">{{ $fmt($inv->discount) }}</td>
                    {{-- ⚠️ المستحق مش الصافي — الجدول ده جنب كشف الحساب
                         اللي بيقيّد الإجمالي، ورقمين مختلفين لنفس الفاتورة
                         على نفس الصفحة بيخلّي اليوزر يشك في السيستم كله. --}}
                    <td class="num pos">{{ $fmt($inv->payable()) }}</td>
                    <td class="num">{{ $inv->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

<div class="card">
    <h3>📋 {{ __('client.statement') }} <span class="side">{{ __('client.transaction_countable', ['count' => $txns->total()]) }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('common.date') }}</th><th>{{ __('client.type') }}</th><th>{{ __('client.memo') }}</th><th>{{ __('client.debit') }}</th><th>{{ __('client.credit') }}</th></tr>
            @foreach ($txns as $t)
                <tr>
                    <td class="num">{{ $t->date->format('Y-m-d') }}</td>
                    <td><span class="badge b-gray">{{ $t->kindLabel() }}</span></td>
                    <td style="white-space:normal;max-width:520px">{{ $t->memo }}</td>
                    <td class="num">{{ $t->debit > 0 ? $fmt($t->debit) : '—' }}</td>
                    <td class="num pos">{{ $t->credit > 0 ? $fmt($t->credit) : '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    <div class="pag">{{ $txns->links('pagination::simple-default') }}</div>
</div>

@if ($manager)
<dialog id="dlgEdit">
    
    {{-- ⚠️ `enctype` لازم — من غيره ملف العقد بيتبعت كنص فاضي
         و`hasFile()` بترجّع false، فالمستخدم بيرفع ومفيش حاجة بتتخزن. --}}
    <form class="dlg" method="POST" action="{{ route('erp.clients.update', $c) }}" id="edClientForm"
          enctype="multipart/form-data" style="max-height:86vh;overflow-y:auto">
        @csrf @method('PUT')
        <h4>{{ __('client.edit_client_named', ['name' => $c->displayName()]) }}</h4>

        
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.client') }}</div>
        <div class="frow">
            {{-- ⚠️ **الإنجليزي الأول هنا كمان.** الشاشتين لازم يبقوا
                 بنفس الترتيب — اللي بيدخل الداتا بيتعلّم مكان الخانة
                 بعينه، وقلبها بين شاشة وشاشة بيخلّيه يكتب في الغلط. --}}
            <div><label class="f">{{ __('client.name_en_field') }}</label><input type="text" name="name_en" dir="ltr" value="{{ old('name_en', $c->name_en) }}" maxlength="190" style="width:100%" placeholder="{{ __('client.name_en_ph') }}"></div>
            <div><label class="f">{{ __('client.name_ar_field') }}</label><input type="text" name="name" value="{{ old('name', $c->name) }}" maxlength="190" required style="width:100%" placeholder="{{ __('client.name_ar_ph') }}"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('common.phone') }}</label><input type="text" name="phone" value="{{ $c->phone }}" maxlength="30" style="width:100%"></div>
            <div>
                <label class="f">{{ __('geo.governorate') }}</label>
                <select name="governorate" style="width:100%">
                    <option value="">{{ __('geo.pick_governorate') }}</option>
                    @foreach ($governorates as $gk => $gLabel)
                        <option value="{{ $gk }}" @selected($c->governorate === $gk)>{{ $gLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('common.address') }} <span style="color:var(--muted);font-weight:400">· EN</span></label><input type="text" name="address" value="{{ $c->address }}" dir="ltr" maxlength="190" placeholder="{{ __('client.address_ph') }}" style="width:100%"></div>
        </div>
        <div class="frow">
            <div style="grid-column:1/-1">
                <label class="f">{{ __('geo.location_url') }}</label>
                <input type="url" name="location_url" dir="ltr" maxlength="500"
                       value="{{ $c->location_url }}" style="width:100%" placeholder="https://maps.app.goo.gl/...">
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('branch.branch') }}</label>
                <select name="branch_id" style="width:100%">
                    <option value="">{{ __('branch.central') }}</option>
                    @foreach ($branches as $br)
                        <option value="{{ $br->id }}" @selected($c->branch_id === $br->id)>{{ $br->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('client.zone') }}</label>
                <select name="zone_id" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($zones as $z)<option value="{{ $z->id }}" @selected($c->zone_id === $z->id)>{{ $z->displayName() }}</option>@endforeach
                </select>
            </div>
            <div>
                {{-- ⚠️ **المدير مش المندوب.** المندوب اتشال من الفورم ده —
                     تخصيصه من شاشة توزيع المناطق لأنه بيتغيّر مع خط
                     السير، وحفظ بيانات العميل مالوش يعيد توزيعه. --}}
                <label class="f">{{ __('client.account_manager') }}</label>
                <select name="manager_id" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($managers as $m)<option value="{{ $m->id }}" @selected($c->manager_id === $m->id)>{{ $m->displayName() }}</option>@endforeach
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.account_manager_hint') }}</div>
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.channel') }}</label>
                <select name="channel_id" id="edChannelSel" style="width:100%" onchange="edSyncSubChannel()">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($channels as $ch)
                        <option value="{{ $ch->id }}" data-code="{{ $ch->code }}" @selected($c->channel_id === $ch->id)>
                            {{ $ch->displayName() }}
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- ⚠️ القسم للكي أكاونت وبس. السلاسل والكونفينيانس بيتعاملوا
                 مختلف تماماً؛ الأونلاين والكاش فان والجملة متجانسين
                 جواهم. السيرفر بيصفّي القسم في `Client::booted()` لو
                 القناة اتغيّرت — ده هنا عشان المستخدم يشوف. --}}
            <div id="edSubChannelBox" style="display:none"><label class="f">{{ __('client.key_account_segment') }}</label>
                <select name="sub_channel" style="width:100%">
                    <option value="">— {{ __('client.not_applicable') }} —</option>
                    @foreach (array_keys(\App\Models\Channel::SUB_CHANNELS) as $k)
                        <option value="{{ $k }}" @selected($c->sub_channel === $k)>{{ __('enums.sub_channel.'.$k) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.chain') }}</label>
                <select name="group_id" style="width:100%">
                    <option value="">— {{ __('client.independent') }} —</option>
                    @foreach ($groupOpts as $grp)
                        <option value="{{ $grp->id }}" @selected($c->group_id === $grp->id)>{{ $grp->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('client.category') }}</label>
                <select name="category" style="width:100%" required>
                    @foreach (Client::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected($c->category === $k)>{{ __('enums.category.'.$k) }}</option>@endforeach
                </select>
            </div>
        </div>
        {{-- ═════ طرق التواصل ═════ --}}
        {{-- ⚠️ الفهرس مكتوب صراحةً. `contacts[][name]` بيخلّي PHP يعمل
             عنصر جديد لكل حقل — الاسم في صف والتليفون في صف تاني. --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 8px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.contacts') }}</div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:8px">{{ __('client.contacts_hint') }}</div>
        <div id="edContactRows">
            @foreach ($c->contactList() as $i => $ctc)
                <div class="frow contact-row" style="margin-bottom:6px">
                    <div><input type="text" name="contacts[{{ $i }}][name]" dir="ltr" maxlength="120" value="{{ $ctc['name'] }}" placeholder="{{ __('client.contact_name_ph') }}" style="width:100%"></div>
                    <div><input type="text" name="contacts[{{ $i }}][role]" dir="ltr" maxlength="120" value="{{ $ctc['role'] }}" placeholder="{{ __('client.contact_role_ph') }}" style="width:100%"></div>
                    <div style="display:flex;gap:6px">
                        <input type="text" name="contacts[{{ $i }}][phone]" dir="ltr" maxlength="30" value="{{ $ctc['phone'] }}" placeholder="01000000000" style="flex:1;min-width:0">
                        <button type="button" class="btn sm red" onclick="this.closest('.contact-row').remove()">&times;</button>
                    </div>
                </div>
            @endforeach
        </div>
        <button type="button" class="btn sm" onclick="addContactRow('edContactRows')">+ {{ __('client.add_contact') }}</button>

        <div class="frow" style="margin-top:12px">
            <div><label class="f">{{ __('client.lat') }}</label><input type="text" name="lat" value="{{ $c->lat }}" placeholder="30.0566" style="width:100%"></div>
            <div><label class="f">{{ __('client.lng') }}</label><input type="text" name="lng" value="{{ $c->lng }}" placeholder="31.3450" style="width:100%"></div>
        </div>

        
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('ops.pricing') }}</div>
        <div style="margin-bottom:12px">
            <label class="f">{{ __('client.price_list') }}</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;font-weight:700">
                <label style="display:flex;gap:6px;align-items:center">
                    <input type="radio" name="price_list" value="new" @checked($c->price_list !== 'old')> {{ __('stock.price_list_new') }}

                </label>
                <label style="display:flex;gap:6px;align-items:center">
                    <input type="radio" name="price_list" value="old" @checked($c->price_list === 'old')> {{ __('stock.price_list_old') }}

                </label>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.price_list_hint') }}</div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('client.custom_discount') }} %</label>
                <input type="number" step="0.5" min="0" max="100" name="discount" value="{{ round($c->discount * 100, 1) }}" required style="width:100%">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.custom_discount_hint') }}</div>
            </div>
        </div>

        
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.tax') }}</div>
        {{-- ⚠️ الملاحظة بتتغير حسب الحالة الفعلية. رسالة ثابتة بتقول
             «الضريبة متوقفة» بتفضل ظاهرة بعد ما تتفعّل وبتكدّب على اليوزر. --}}
        @if (! \App\Services\Tax::enabled())
            <div class="alert info"><span>ℹ️</span><span>{{ __('client.tax_off_note') }}</span></div>
        @endif
        <div class="frow">
            <div>
                <label class="f">{{ __('common.status') }}</label>
                <input type="hidden" name="taxable" value="0">
                <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;font-weight:700">
                    <input type="checkbox" name="taxable" value="1" @checked($c->taxable)> {{ __('client.taxable') }}

                </label>
            </div>
            <div><label class="f">{{ __('client.tax_rate') }}</label><input type="number" step="0.5" min="0" max="100" name="tax_rate" value="{{ round($c->tax_rate * 100, 1) }}" style="width:100%"></div>
            <div><label class="f">{{ __('client.tax_id') }}</label><input type="text" name="tax_id" value="{{ $c->tax_id }}" maxlength="40" style="width:100%"></div>
            <div>
                <label class="f">{{ __('client.tax_cycle') }}</label>
                <select name="tax_cycle" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach (Client::TAX_CYCLES as $cycle)
                        <option value="{{ $cycle }}" @selected($c->tax_cycle === $cycle)>{{ __('client.tax_cycle_'.$cycle) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('client.eta_type') }}</label>
                <select name="eta_type" style="width:100%">
                    {{-- ⚠️ الخيار الفاضي لازم يفضل موجود. من غيره العميل
                         اللي متسابله فاضي عن قصد كان بياخد `B` (شخص
                         اعتباري) أول ما حد يفتح الكارت ويضغط حفظ،
                         والتصدير للمصلحة بيطلع بنوع مستلم غلط. --}}
                    <option value="">— {{ __('common.none') }} —</option>
                    <option value="B" @selected($c->eta_type === 'B')>{{ __('client.eta_type_b') }}</option>
                    <option value="P" @selected($c->eta_type === 'P')>{{ __('client.eta_type_p') }}</option>
                </select>
            </div>
        </div>

        
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.contract') }}</div>
        @if ($inherited)
            {{-- ⚠️ الفرع ده متغطي بعقد سلسلته. لو علّم الخانة وحفظ،
                 بيتعمل له عقد خاص بيحجب عقد السلسلة — فبنقول له
                 الحقيقة ونوديه لصفحة عقد السلسلة بدل ما يجرّب. --}}
            <div class="alert info" style="margin-bottom:10px">
                <span>🏬</span>
                <span>
                    {{ __('client.contract_from_chain_note', ['chain' => $ct->group?->displayName() ?? $ct->displayChain()]) }}
                    <a href="{{ route('erp.contracts.show', $ct) }}">{{ __('client.contract_page') }} ←</a>
                </span>
            </div>
        @endif
        <input type="hidden" name="has_contract" value="0">
        <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;font-weight:800;margin-bottom:10px">
            <input type="checkbox" name="has_contract" value="1" id="edHasContract" @checked($hasContract)
                   onchange="toggleContractBox('edHasContract','edContractBox')">
            {{ __('client.has_contract') }}

        </label>
        <div id="edContractBox" style="display:{{ $hasContract ? '' : 'none' }}">
            <div class="alert warn" style="margin-bottom:12px"><span>📜</span><span>{{ __('client.contract_wins_note') }}</span></div>
            <div class="frow">
                @php
    // ⚠️ **النوع القديم بيبان بس لو العقد ده نوعه.**
                    // الـ22 عقد الحقيقيين فيهم `supply_agreement` و
                    // `annual` — مش في القايمة الجديدة. لو خبّيناهم
                    // خالص، فتح عقد قديم بيوري دروب داون فاضية وأول
                    // حفظ بيغيّر نوع العقد في صمت.
                    $edType = old('contract_type', $own?->type_key);
                    $edTypes = \App\Models\Contract::TYPE_CHOICES;

                    if ($edType && ! in_array($edType, $edTypes, true)) {
                        $edTypes[] = $edType;
                    }
                @endphp
                <div><label class="f">{{ __('client.contract_type') }} <b class="req-star">*</b></label>
                    <select name="contract_type" style="width:100%" data-req-contract
                            class="{{ $errors->has('contract_type') ? 'bad' : '' }}">
                        <option value="">— {{ __('client.pick_contract_type') }} —</option>
                        @foreach ($edTypes as $tk)
                            <option value="{{ $tk }}" @selected($edType === $tk)>{{ __('client.contract_type_'.$tk) }}</option>
                        @endforeach
                    </select>
                    @error('contract_type')<div class="errline">{{ $message }}</div>@enderror
                </div>
                <div><label class="f">{{ __('client.duration') }} <b class="req-star">*</b></label>
                    {{-- ⚠️ المدة بتحدد يعني إيه التواريخ الفاضية:
                         «مفتوح المدة» ولا «حد نسي يملاها». --}}
                    <select name="contract_duration" style="width:100%" data-req-contract
                            class="{{ $errors->has('contract_duration') ? 'bad' : '' }}">
                        <option value="">— {{ __('client.pick_duration') }} —</option>
                        @foreach (array_keys(\App\Models\Contract::DURATIONS) as $dk)
                            <option value="{{ $dk }}" @selected(old('contract_duration', $own?->duration) === $dk)>{{ __('client.duration_'.$dk) }}</option>
                        @endforeach
                    </select>
                    @error('contract_duration')<div class="errline">{{ $message }}</div>@enderror
                </div>
                {{-- ⚠️ **خصم الفاتورة حقل أساسي مش تشيك بوكس.** هو البند
                     الوحيد اللي بينزل على سعر البيع، وكل عقد تقريباً فيه
                     واحد. لما كان مخبّي مع البنود النادرة كان بيتنسى. --}}
                <div>
                    <label class="f">{{ __('client.preset_invoice_discount') }} % <b class="req-star">*</b></label>
                    @if ($presets['invoice_discount']['locked'])
                        <input type="hidden" name="clause[invoice_discount][on]" value="0">
                        {{-- ⚠️ **القيمة الحقيقية بتتبعت في حقل مخفي.** الخانة
                             اللي المستخدم شايفها `disabled` فمابتتبعتش خالص،
                             و`required_if:has_contract,1` كانت بترفض حفظ الـ17
                             عميل اللي بندهم مكتوب بإيد من الـPDF — يعني حتى
                             تصليح رقم تليفون كان بيترفض، من غير أي خانة حمرا
                             تقول له فين المشكلة.
                             و`on = 0` فاضلة زي ما هي: `syncClauses()` بتتخطّى
                             البنود المقفولة أصلاً، فمفيش حاجة بتتكتب. --}}
                        <input type="hidden" name="clause[invoice_discount][value]"
                               value="{{ $presets['invoice_discount']['value'] }}">
                        <input type="number" style="width:100%" disabled data-always-disabled="1"
                               value="{{ $presets['invoice_discount']['value'] }}">
                        <div style="font-size:10.5px;color:var(--muted);margin-top:5px">🔒 {{ __('client.clause_locked_hint') }}</div>
                    @else
                        <input type="hidden" name="clause[invoice_discount][on]" value="1">
                        {{-- ⚠️ `data-req-contract` لازم: من غيرها المستخدم
                             بيفضّي الخانة، الجافاسكربت بيسيبه يبعت، والسيرفر
                             بيرفض — وهو راجع لمودال مفيهوش أي حاجة حمرا. --}}
                        <input type="number" step="0.5" min="0" max="100" style="width:100%"
                               name="clause[invoice_discount][value]" data-req-contract
                               class="{{ $errors->has('clause.invoice_discount.value') ? 'bad' : '' }}"
                               value="{{ old('clause.invoice_discount.value', $presets['invoice_discount']['value'] ?: 0) }}">
                        @error('clause.invoice_discount.value')<div class="errline">{{ $message }}</div>@enderror
                    @endif
                </div>
            </div>

            {{-- ═════ بنود الخصم — نفس بلوك شاشة العميل الجديد ═════ --}}
            {{-- ⚠️ **ممنوع يتشال من هنا.** لو الشاشة دي حفظت من غير
                 بلوك البنود، `ContractIntake::syncClauses` بتستقبل مصفوفة
                 فاضية وبتمسح كل البنود الجاهزة والخصم بينزل صفر. --}}
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:14px 0 9px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.discount_clauses') }}</div>
            <div class="alert warn" style="margin-bottom:10px"><span>⚠️</span><span>{{ __('client.only_invoice_discount_note') }}</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:9px;margin-bottom:12px">
                @foreach (\App\Models\Contract::CLAUSE_PRESETS as $pk => $spec)
                    {{-- خصم الفاتورة طلع فوق كحقل أساسي --}}
                    @continue($pk === 'invoice_discount')
                    @php
                        $isPct = $spec['mode'] === 'pct';
                        $locked = $presets[$pk]['locked'];
                    @endphp
                    <div style="border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--card2)">
                        <label style="display:flex;gap:7px;align-items:center;font-size:12px;font-weight:800;cursor:{{ $locked ? 'default' : 'pointer' }}">
                            <input type="hidden" name="clause[{{ $pk }}][on]" value="0">
                            {{-- ⚠️ البند المقفول ليه نظير مكتوب بإيد من العقد
                                 الأصلي. لو الفورم كتب بند جاهز من نفس النوع،
                                 الاتنين بيتجمعوا و`recalcFromClauses()` بتضاعف
                                 الخصم — On The Run من 15% لـ 30% في صمت.
                                 الخانة معطّلة، والسيرفر بيتجاهل البند ده أصلاً. --}}
                            <input type="checkbox" name="clause[{{ $pk }}][on]" value="1" id="ed_cl_{{ $pk }}"
                                   @checked($presets[$pk]['on']) @disabled($locked)
                                   onchange="toggleEdClause('{{ $pk }}')">
                            {{ __('client.preset_'.$pk) }}
                            <span class="b {{ $locked ? 'b-orange' : ($isPct ? 'b-green' : 'b-gray') }}" style="margin-inline-start:auto;font-size:10.5px">
                                {{ $locked ? '🔒' : ($isPct ? '%' : __('common.currency')) }}
                            </span>
                        </label>
                        <div id="ed_box_{{ $pk }}" style="display:{{ $presets[$pk]['on'] ? '' : 'none' }};margin-top:8px">
                            <input type="number" name="clause[{{ $pk }}][value]"
                                   step="{{ $isPct ? '0.5' : '1' }}" min="0" max="{{ $isPct ? '100' : '99999999' }}"
                                   value="{{ $presets[$pk]['value'] ?: '' }}" style="width:100%"
                                   @disabled($locked) @if ($locked) data-always-disabled="1" @endif>
                            @if ($locked)
                                <div style="font-size:10.5px;color:var(--muted);margin-top:5px">{{ __('client.clause_locked_hint') }}</div>
                            @else
                                <input type="text" name="clause[{{ $pk }}][note]" maxlength="500"
                                       value="{{ $presets[$pk]['note'] }}" style="width:100%;margin-top:6px"
                                       placeholder="{{ __('common.notes') }}">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="frow">
                <div><label class="f">{{ __('client.starts_at') }}</label><input type="date" name="contract_starts_at" value="{{ $own?->starts_at?->toDateString() ?? today()->toDateString() }}" style="width:100%"></div>
                <div><label class="f">{{ __('client.ends_at') }}</label><input type="date" name="contract_ends_at" value="{{ $own?->ends_at?->toDateString() }}" style="width:100%"></div>
                <div><label class="f">{{ __('client.payment_days') }}</label><input type="number" step="1" min="0" max="365" name="contract_payment_days" id="edPaymentDays" value="{{ old('contract_payment_days', $own?->paymentDays()) }}" style="width:100%"></div>
                <div>
                    <label class="f">{{ __('client.days_counted_from') }}</label>
                    {{-- ⚠️ الرقم من غير نقطة بداية مالوش معنى: 60 يوم من
                         أول توريد غير 60 يوم من كل فاتورة. --}}
                    <select name="contract_payment_days_from" style="width:100%" id="edDaysFrom"
                            class="{{ $errors->has('contract_payment_days_from') ? 'bad' : '' }}">
                        <option value="">— {{ __('client.pick_days_basis') }} —</option>
                        @foreach (\App\Models\Contract::DAYS_FROM as $basis)
                            <option value="{{ $basis }}"
                                @selected(old('contract_payment_days_from', $own?->paymentBasis()) === $basis)>
                                {{ __('client.days_from_'.$basis) }}
                            </option>
                        @endforeach
                    </select>
                    @error('contract_payment_days_from')<div class="errline">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.contract_file') }}</label>
                    <input type="file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png" style="width:100%">
                    <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.contract_file_hint') }}</div>
                </div>
            </div>
            {{-- ⚠️ اسم السلسلة في العقد نص عربي من العقد الأصلي — نعرضه
                 للتعديل في الواجهة العربية بس. --}}
            @if (app()->getLocale() === 'ar')
            <div style="margin-bottom:12px"><label class="f">{{ __('common.notes') }} <span style="color:var(--muted);font-weight:400">· EN</span></label><textarea name="contract_note" dir="ltr" rows="2" style="width:100%">{{ $own?->note ?? '' }}</textarea></div>
            <div>
                <label class="f">{{ __('client.contract_clauses') }}</label>
                <div id="edClauseRows">
                    @foreach (($own?->clauses ?? []) as $clause)
                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                            <input type="text" name="contract_clauses[]" dir="ltr" value="{{ $clause }}" maxlength="500" style="flex:1;min-width:0">
                            <button class="btn sm red" type="button" onclick="this.parentNode.remove()">{{ __('client.remove_clause') }}</button>
                        </div>
                    @endforeach
                </div>
                <button class="btn sm" type="button" onclick="addClauseRow('edClauseRows')">+ {{ __('client.add_clause') }}</button>
            </div>
            @else
                {{-- ⚠️ **الحقول دي بتتبعت مخفية.** الحفظ من الشاشة الإنجليزية
                     كان بيوصل من غير `contract_clauses` ولا `contract_chain`
                     ولا `contract_note`، والكنترولر بيعتبرها اتفضّت عن قصد
                     فبيمسحها — يعني بنود العقد العربية بتضيع لمجرد إن حد
                     فتح الشاشة بالإنجليزي وضغط حفظ. --}}
                <input type="hidden" name="contract_note" value="{{ $own?->note ?? '' }}">
                @foreach (($own?->clauses ?? []) as $clause)
                    <input type="hidden" name="contract_clauses[]" value="{{ $clause }}">
                @endforeach

                {{-- النصوص الحرة دي محرّرة بالعربي في العقد الأصلي. تحريرها
                     من شاشة إنجليزية معناه خانة عربية جوه فورم إنجليزي. --}}
                <div class="alert info">
                    {{ __('client.free_text_ar_only') }}
                    @if ($own)
                        <a href="{{ route('erp.contracts.show', $own) }}">{{ __('client.contract_page') }}</a>
                    @endif
                </div>
            @endif
        </div>

        <div style="margin-top:14px"><label class="f">{{ __('common.notes') }} <span style="color:var(--muted);font-weight:400">· EN</span></label><textarea name="notes" dir="ltr" rows="2" style="width:100%">{{ $c->notes }}</textarea></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgEdit')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

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
    <form class="dlg" method="POST" action="{{ route('erp.clients.collect', $c) }}">
        @csrf
        <h4>{{ __('client.record_collection_from', ['client' => $c->displayName()]) }}</h4>
        <div class="frow">
            <div><label class="f">{{ __('common.amount') }}</label><input type="number" step="0.01" name="amount" required style="width:100%"></div>
            <div><label class="f">{{ __('common.date') }}</label><input type="date" name="date" value="{{ today()->toDateString() }}" style="width:100%"></div>
        </div>
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
function toggleContractBox(cbId, boxId) {
    const cb = document.getElementById(cbId);
    const box = document.getElementById(boxId);
    if (!cb || !box) return;

    box.style.display = cb.checked ? '' : 'none';

    // ⚠️ **`display:none` مابيمنعش الإرسال.** الحقول المقفولة بتفضل
    // بتتبعت — يعني `clause[invoice_discount][on]=1` بيوصل مع خانة
    // قيمة المستخدم فضّاها، وقاعدة `required_if:clause.*.on,1` بترفض
    // الحفظ برسالة على بند جوه بلوك مقفول مفيش خانة تصلّحه.
    // العطّل بيمنع كمان تاريخ البداية وأيام السداد إنهم يتسرّبوا في
    // حفظ عميل المستخدم شال عنه العقد.
    // ⚠️ الحقل اللي كان `disabled` أصلاً (البند المقفول) بيفضل معطّل:
    // بنعلّم عليه عشان مانرجّعهوش بالغلط.
    box.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (el.dataset.alwaysDisabled === '1') return;

        el.disabled = ! cb.checked;
    });
}

// القسم للكي أكاونت وبس — والقيمة بتتفضّى لما القناة تتغيّر
function edSyncSubChannel() {
    const sel = document.getElementById('edChannelSel');
    const box = document.getElementById('edSubChannelBox');
    if (!sel || !box) return;

    const sub = box.querySelector('select');
    const code = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.code : '';
    const allowed = (code === 'key_account');

    // ⚠️ `display` مش `visibility` — الخانة بتختفي بمكانها. خانة
    // فاضية بعنوان «قسم الكي أكاونت» على عميل أونلاين بتخلّي اللي
    // بيقرا الكارت يفتكر إن فيه بيانات ناقصة.
    box.style.display = allowed ? '' : 'none';
    // ⚠️ الإخفاء لوحده مش كفاية — الخانة المخبّية بتفضل شايلة قيمتها
    // وبتتبعت مع الفورم.
    if (!allowed && sub) sub.value = '';
}

document.addEventListener('DOMContentLoaded', edSyncSubChannel);

// ⚠️ لازم يتنادى عند الفتح كمان مش عند التغيير بس — العميل اللي مالوش
// عقد بيفتح كارته والبلوك مقفول، وحقوله لسه شغّالة وهتتبعت أول حفظ.
document.addEventListener('DOMContentLoaded', function () {
    toggleContractBox('edHasContract', 'edContractBox');
});

// ═══════════ الحقول الإجبارية في مودال التعديل ═══════════
// ⚠️ **مش `required` بتاع HTML.** بلوك العقد بيتخبّى بـ`display:none`،
// والمتصفح بيرفض يعمل submit ويقول «An invalid form control is not
// focusable» من غير ما يوري المستخدم أي حاجة — الزرار بيبقى ميت.
const ED_REQUIRED = '{{ __('common.field_required') }}';

function edMarkBad(el, message) {
    el.classList.add('bad');
    let line = el.parentElement.querySelector('.errline.js-err');
    if (!line) {
        line = document.createElement('div');
        line.className = 'errline js-err';
        el.parentElement.appendChild(line);
    }
    line.textContent = message;
}

function edClearBad(el) {
    el.classList.remove('bad');

    // ⚠️ رسالة السيرفر بتتشال كمان — مش بتاعت الجافاسكربت بس. لو
    // فضلت، المستخدم بيصلّح الخانة والسطر الأحمر فاضل تحتها وبيفضل
    // يدوّر على غلط مش موجود.
    el.parentElement.querySelectorAll('.errline').forEach(l => l.remove());
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('edClientForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const hasContract = document.getElementById('edHasContract').checked;
        let firstBad = null;

    // ⚠️ الخانة المخبّية بتتستثنى: العميل اللي مالوش عقد بيعدّي
        // من غير نوع عقد، والقسم مابيتطلبش على قناة مش كي أكاونت.
        if (hasContract) {
            form.querySelectorAll('[data-req-contract]').forEach(function (el) {
                if (el.offsetParent === null) return;

                if (String(el.value).trim() === '') {
                    edMarkBad(el, ED_REQUIRED);
                    if (!firstBad) firstBad = el;
                } else {
                    edClearBad(el);
                }
            });
        }

    // ⚠️ أيام السداد وأساسها بيمشوا مع بعض — 60 يوم من أول توريد
        // غير 60 يوم من كل فاتورة. شرط مربوط فمينفعش يتحط `data-req`.
        const days = document.getElementById('edPaymentDays');
        const basis = document.getElementById('edDaysFrom');

        if (days && basis && basis.offsetParent !== null) {
            if (String(days.value).trim() !== '' && basis.value === '') {
                edMarkBad(basis, ED_REQUIRED);
                if (!firstBad) firstBad = basis;
            } else {
                edClearBad(basis);
            }
        }

        if (firstBad) {
            e.preventDefault();
            firstBad.focus();
            firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    });

    // الأحمر بيتشال أول ما المستخدم يصلّح
    ['input', 'change'].forEach(function (evt) {
        form.addEventListener(evt, function (e) {
            if (e.target.classList && e.target.classList.contains('bad')) edClearBad(e.target);
        });
    });
});

{{-- ⚠️ المودال بيتفتح تلقائي لو رجع خطأ من السيرفر. من غير كده
     المستخدم بيرجع لكارت العميل، المودال مقفول، والرسالة الحمرا
     جوّاه — فبيشوف صفحة سليمة ومش فاهم ليه الحفظ مانفعش. --}}
@if ($errors->any())
document.addEventListener('DOMContentLoaded', function () {
    openDlg('dlgEdit');
    const bad = document.querySelector('#dlgEdit .bad');
    if (bad) bad.scrollIntoView({ block: 'center' });
});
@endif

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
</script>

@endsection
