@extends('layouts.system')

{{--
    تحصيلات الميدان (2026-08-09) — كل قيود collection بطرقها
    وصور إثباتها. المحاسب بيطابق الشيك على صورته والتحويل على مرجعه.
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@php
    // ⭐ ٩/٩ — نفس الفيو لشاشتين: «تحصيلات الميدان» (كل المصادر بفلتر)
    // و«التحصيلات المباشرة» (مقفولة على المباشر + عمود الضرايب المخصومة)
    $direct = ($mode ?? 'all') === 'direct';
    $titleKey = $direct ? 'nav.collections_direct' : 'nav.collections';
@endphp
@section('title', __($titleKey))

@section('actions')
    {{-- التصدير من نفس الفلتر — الصفحة بتتشال، الملف مرآة الشاشة --}}
    <a class="btn" href="{{ url()->current().'?'.http_build_query(array_merge(request()->except('page'), ['export' => 1])) }}">📊 {{ __('ops.inv_excel') }}</a>
    <button class="btn" type="button" onclick="window.print()" title="{{ __('ops.pdf_hint') }}">📄 {{ __('ops.save_pdf') }}</button>
@endsection

@section('content')

{{-- ═══ إجماليات حسب الطريقة — للمطابقة السريعة ═══ --}}
<div class="kpis">
    @foreach (\App\Models\Transaction::METHODS as $m)
        <div class="kpi">
            <div class="lbl">{{ __('client.pay_method_'.$m) }}</div>
            <div class="val">{{ $fmt($totals[$m]->total ?? 0) }}</div>
            <div class="sub2">{{ number_format($totals[$m]->cnt ?? 0) }} {{ __('ops.entries') }}</div>
        </div>
    @endforeach
    @if ($direct)
        {{-- الضرايب المخصومة تحت الحساب في نفس الفترة — قيود taxded المباشرة --}}
        <div class="kpi">
            <div class="lbl">{{ __('ops.tax_withheld_col') }}</div>
            <div class="val">{{ $fmt($taxTotal) }}</div>
            <div class="sub2">{{ __('ops.tax_withheld_kpi_sub') }}</div>
        </div>
    @endif
</div>

<div class="card">
    <h3>{{ $direct ? '🏦' : '🧾' }} {{ __($titleKey) }}
        <span class="side">{{ __($direct ? 'ops.collections_direct_sub' : 'ops.collections_sub') }}</span></h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        @unless ($direct)
            <div>
                <label class="f">{{ __('ops.source') }}</label>
                <select name="source" onchange="this.form.submit()">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach (\App\Http\Controllers\CollectionController::SOURCES as $src)
                        <option value="{{ $src }}" @selected($source === $src)>{{ __('ops.source_'.$src) }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div>
            <label class="f">{{ __('ops.method') }}</label>
            <select name="method" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                @foreach (\App\Models\Transaction::METHODS as $m)
                    <option value="{{ $m }}" @selected($method === $m)>{{ __('client.pay_method_'.$m) }}</option>
                @endforeach
            </select>
        </div>
        @unless ($direct)
            <div>
                <label class="f">{{ __('ops.rep') }}</label>
                <select name="rep" onchange="this.form.submit()">
                    <option value="0">{{ __('common.all') }}</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected($repId === $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div>
            <label class="f">{{ __('common.from') }}</label>
            <input type="date" name="from" value="{{ $from }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('common.to') }}</label>
            <input type="date" name="to" value="{{ $to }}" onchange="this.form.submit()">
        </div>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th style="text-align:start">{{ __('client.client') }}</th>
                <th>{{ __('common.date') }}</th>
                <th>{{ __('ops.collected_by') }}</th>
                <th>{{ __('ops.method') }}</th>
                {{-- ⚠️ data-nosum — رقم التحويل مرجع مش مبلغ، مجموعه غلط (١١/٨) --}}
                <th data-nosum>{{ __('ops.reference') }}</th>
                <th>{{ __('settle.proof') }}</th>
                @if ($direct)<th>{{ __('ops.tax_withheld_col') }}</th>@endif
                <th>{{ __('common.total') }}</th>
            </tr>
            @forelse ($rows as $t)
                @php
                    $visit = $t->source_type === \App\Models\Visit::class ? $repByVisit->get($t->source_id) : null;
                    // المستند اليدوي بينسب التحصيل للمندوب بـ source_type = User (انظر ٦/٩)
                    $manualRep = $t->source_type === \App\Models\User::class ? $repByUser->get($t->source_id) : null;
                @endphp
                <tr>
                    <td style="text-align:start">
                        <a href="{{ route('erp.clients.show', $t->client_id) }}"><b>{{ $t->client?->fullName() ?? '—' }}</b></a>
                        @if ($t->memo)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $t->memo }}</div>
                        @endif
                    </td>
                    <td class="num" style="font-size:11px">{{ $t->created_at->format('m-d h:i A') }}</td>
                    <td>
                        @if ($visit?->user)
                            {{ $visit->user->name }}
                            <div style="font-size:10px;color:var(--muted)">{{ $visit->user->code }}</div>
                        @elseif ($manualRep)
                            {{ $manualRep->name }}
                            <div style="font-size:10px;color:var(--muted)">
                                {{ $manualRep->code }} · {{ __('ops.office_entry') }}
                            </div>
                        @else
                            <span class="badge b-gray">{{ __('ops.office_entry') }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $t->method === 'cash' ? 'b-green' : 'b-blue' }}">{{ $t->methodLabel() ?? '—' }}</span>
                        @if ($t->method === 'cheque' && $t->cheque_due)
                            <div style="font-size:10px;color:var(--muted)">
                                {{ $t->cheque_bank }} · {{ __('client.cheque_due_short') }} {{ $t->cheque_due->format('Y-m-d') }}
                            </div>
                        @endif
                    </td>
                    <td class="num" style="font-size:11px">{{ $t->reference ?: '—' }}</td>
                    <td>
                        @if ($t->proofUrl())
                            <a class="btn sm" href="{{ $t->proofUrl() }}" target="_blank">📷 {{ __('common.view') }}</a>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    @if ($direct)
                        @php $wt = (float) ($taxByKey->get(\App\Http\Controllers\CollectionController::taxKey($t)) ?? 0); @endphp
                        <td class="num">{{ $wt > 0 ? $fmt($wt) : '—' }}</td>
                    @endif
                    <td class="num pos"><b>{{ $fmt($t->credit) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="{{ $direct ? 8 : 7 }}" style="text-align:center;color:var(--muted);padding:26px">{{ __('ops.no_collections') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div style="margin-top:12px">{{ $rows->links() }}</div>
</div>

@endsection
