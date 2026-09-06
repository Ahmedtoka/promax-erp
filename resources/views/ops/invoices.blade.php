@extends('layouts.system')

@section('title', __('ops.invoices'))

@section('actions')
    {{-- ═══ تصدير إكسيل (٢٨/٨) — **بنفس الفلاتر المطبّقة دلوقتي**:
         `request()->query()` بيمرّر الفترة والمندوب والسيريال زي ما
         هُم فالملف مرآة الشاشة. الصفحة (`page`) بتتشال — التصدير
         بياخد نتيجة الفلتر كله مش الأربعين المعروضين ═══ --}}
    <a class="btn"
       href="{{ route('ops.invoices', array_merge(request()->except('page'), ['export' => 1])) }}">
        📊 {{ __('ops.inv_excel') }}
    </a>
    @if (auth()->user()?->role === 'admin')
        {{-- إعادة الترقيم بالتاريخ (٢٢/٨): الفواتير المتأخرة بالمستند
             اليدوي بتاخد أرقام قدام تاريخها — الزرار بيرتّب INV-1001
             فما فوق حسب created_at، والقيود بتتصحح معاها --}}
        <form method="POST" action="{{ route('ops.invoices.renumber') }}" style="display:inline"
              onsubmit="return confirm(@js(__('ops.renumber_confirm')))">
            @csrf
            <button class="btn" type="submit">🔢 {{ __('ops.renumber_btn') }}</button>
        </form>
    @endif
@endsection

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('content')

{{-- ═══ السامري — نتيجة الفلتر كله مش الصفحة (١٩ أغسطس ٢٠٢٦) ═══ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('ops.inv_count') }}</div>
        <div class="val mid">{{ $fmt($stats->n) }}</div>
        <div class="sub2">💵 {{ $fmt($stats->cash_n) }} {{ __('ops.cash') }} · 🕐 {{ $fmt($stats->credit_n) }} {{ __('ops.credit') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('common.subtotal') }}</div>
        <div class="val">{{ $fmt($stats->subtotal) }}</div>
        <div class="sub2">{{ __('ops.before_discount_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('common.discount') }}</div>
        <div class="val mid">{{ $fmt($stats->discount) }}</div>
        <div class="sub2">{{ $stats->subtotal > 0 ? number_format($stats->discount / $stats->subtotal * 100, 1) : 0 }}%</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.inv_net') }}</div>
        <div class="val pos">{{ $fmt($stats->total) }}</div>
        <div class="sub2">{{ __('ops.net_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.inv_grand') }}</div>
        <div class="val pos">{{ $fmt($stats->grand) }}</div>
        <div class="sub2">{{ __('tax.tax') }}: {{ $fmt($stats->tax) }}</div>
    </div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <select name="user">
            <option value="">{{ __('ops.all_reps') }}</option>
            @foreach ($field as $f)
                <option value="{{ $f->id }}" @selected((int) ($filters['user'] ?? 0) === $f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        {{-- فلتر السيريال الورقي (٢٢/٨) — بيمسك رقم الفاتورة كمان،
             و(٦/٩) اسم العميل بالبحث الموحّد المتسامح مع الأخطاء.
             dir=auto عشان اسم العميل عربي والسيريال إنجليزي --}}
        <input type="search" name="paper" value="{{ $filters['paper'] ?? '' }}"
               placeholder="🧾 {{ __('ops.paper_filter_ph') }}" dir="auto" style="width:240px">
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.invoices') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('ops.invoice') }}</th>
                <th data-nosum>🧾 {{ __('ops.paper_col') }}</th>
                <th>{{ __('client.client') }}</th>
                <th data-nosum>{{ __('client.channel') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th data-nosum>{{ __('ops.payment') }}</th>
                <th>{{ __('common.subtotal') }}</th><th>{{ __('common.discount') }}</th>
                <th>{{ __('common.total') }}</th><th>{{ __('ops.inv_grand') }}</th>
                <th>{{ __('common.date') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($invoices as $inv)
                <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                    <td><b>{{ $inv->number }}</b></td>
                    {{-- سيريال الورقية المختومة — عمود مستقل (٢٢/٨) --}}
                    <td class="num" dir="ltr">
                        @if ($inv->paper_ref)
                            <b>{{ $inv->paper_ref }}</b>
                        @else
                            <span class="badge b-orange" style="font-size:9.5px">{{ __('ops.paper_missing') }}</span>
                        @endif
                    </td>
                    {{-- الاسم المركّب بعقيدتنا: السلسلة — الفرع --}}
                    <td style="white-space:normal;max-width:240px"><b>{{ $inv->client->fullName() }}</b></td>
                    <td><span class="badge b-purple">{{ $inv->client->channel?->displayName() ?? '—' }}</span></td>
                    <td style="color:var(--muted)">{{ $inv->user->displayName() }}</td>
                    <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                    <td class="num">{{ $fmt($inv->subtotal) }}</td>
                    <td class="num mid">{{ $fmt($inv->discount) }}</td>
                    <td class="num pos"><b>{{ $fmt($inv->total) }}</b></td>
                    <td class="num pos"><b>{{ $fmt($inv->grand_total) }}</b></td>
                    <td class="num">{{ $inv->created_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_invoices_found') }}</td></tr>
            @endforelse
            </tbody>
            {{-- ⚠️ الإجماليات من الكويري المفلترة كلها — مش جمع صفوف
                 الصفحة دي. صفحة ٢ بتوري نفس الأرقام، وده المقصود. --}}
            @if ($stats->n > 0)
                <tfoot>
                <tr style="background:var(--card2);font-weight:900">
                    <td>Σ</td>
                    <td>—</td>
                    <td>{{ __('ops.filter_scope_note', ['n' => $fmt($stats->n)]) }}</td>
                    <td>—</td><td>—</td><td>—</td>
                    <td class="num">{{ $fmt($stats->subtotal) }}</td>
                    <td class="num mid">{{ $fmt($stats->discount) }}</td>
                    <td class="num pos">{{ $fmt($stats->total) }}</td>
                    <td class="num pos">{{ $fmt($stats->grand) }}</td>
                    <td>—</td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div class="pag">{{ $invoices->links('pagination::simple-default') }}</div>
</div>

@endsection
