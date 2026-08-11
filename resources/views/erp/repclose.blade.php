@extends('layouts.system')

{{--
    تصفية المناديب — شاشة الحسابات (2026-08-06):
    صف لكل مندوب بأرقام فترته المفتوحة (من آخر تصفية) ورصيده
    المترحّل — والتصفية نفسها من صفحة المندوب بالتفصيل.
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('title', __('settle.title'))

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

<div class="card">
    <h3>🤝 {{ __('settle.title') }}
        <span class="side">{{ __('settle.hint') }}</span></h3>

    <div class="tablewrap st-tbl">
        <table>
            <tr>
                <th style="text-align:start">{{ __('settle.rep') }}</th>
                <th>{{ __('settle.open_window') }}</th>
                <th>{{ __('settle.cash_sales') }}</th>
                <th>{{ __('settle.credit_sales') }}</th>
                {{-- ⚠️ **تحصيلات غير نقدية** (طلب المالك ١١/٨): التحويل
                     والشيك والفيزا فلوس ماوصلتش إيد المندوب فمش داخلة
                     «المتوقع» النقدي — بس المحاسب لازم يشوفها عشان
                     يعرف المندوب حصّل كام بره الكاش وقت التصفية. --}}
                <th>{{ __('settle.other_collections') }}</th>
                <th>{{ __('settle.cash_refunds') }}</th>
                <th>{{ __('settle.expected') }}</th>
                <th>{{ __('settle.prev_balance') }}</th>
                <th>{{ __('settle.due_total') }}</th>
                {{-- ⚠️ **القايمة كانت فلوس بس.** المحاسب بيفتح كل
                     مندوب واحد واحد عشان يعرف مين عنده عجز بضاعة —
                     والرقم موجود أصلاً في `openFigures`. --}}
                <th>{{ __('settle.goods_match') }}</th>
                <th></th>
            </tr>
            @foreach ($rows as $r)
                @php $rep = $r['rep']; @endphp
                <tr>
                    <td style="text-align:start">
                        <b>{{ $rep->displayName() }}</b>
                        <div style="font-size:10px;color:var(--muted)">{{ $rep->code }}</div>
                    </td>
                    <td class="s">
                        {{ __('settle.invoice_count', ['count' => $r['invoices']->count()]) }}
                        <div style="font-size:10px;color:var(--muted)">
                            {{ $r['from_at'] ? __('settle.since_last').' '.$r['from_at']->format('m-d h:i A') : __('settle.since_start') }}
                        </div>
                    </td>
                    <td class="num"><b>{{ $fmt($r['cash_sales']) }}</b></td>
                    <td class="num" style="color:var(--muted)">{{ $fmt($r['credit_sales']) }}</td>
                    <td class="num">
                        @if ((float) ($r['other_collections_value'] ?? 0) > 0)
                            <b style="color:#0F766E">{{ $fmt($r['other_collections_value']) }}</b>
                            <div style="font-size:9.5px;color:var(--muted)">{{ __('settle.other_collections_hint') }}</div>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td class="num mid">{{ $fmt($r['cash_refunds']) }}</td>
                    <td class="num pos"><b>{{ $fmt($r['expected']) }}</b></td>
                    <td class="num">
                        @if ((float) $r['prev_balance'] > 0)
                            <span class="badge b-red">{{ __('settle.rep_owes') }} {{ $fmt($r['prev_balance']) }}</span>
                        @elseif ((float) $r['prev_balance'] < 0)
                            <span class="badge b-green">{{ __('settle.rep_credit') }} {{ $fmt(abs((float) $r['prev_balance'])) }}</span>
                        @else
                            <span class="badge b-gray">0</span>
                        @endif
                    </td>
                    <td class="num"><b style="color:var(--royal-blue);font-size:14px">{{ $fmt($r['due_total']) }}</b></td>
                    {{-- المحمَّل والعجز — والمرتجع اللي هيتسلّم معاه --}}
                    <td class="num">
                        @if ((int) $r['goods']['diff_qty'] !== 0)
                            <span class="badge b-red">{{ __('settle.shortage') }}
                                {{ number_format((int) $r['goods']['diff_qty']) }}</span>
                        @elseif ((int) $r['goods']['assigned'] > 0)
                            <span class="badge b-green">0 ✓</span>
                        @else
                            <span class="badge b-gray">—</span>
                        @endif
                        @if ((int) $r['goods']['returned_qty'] > 0 || (int) $r['goods']['damaged_qty'] > 0)
                            <div style="font-size:10px;color:var(--muted);margin-top:3px">
                                {{ __('settle.returned_in') }}:
                                {{ number_format((int) $r['goods']['returned_qty']) }}
                                @if ((int) $r['goods']['damaged_qty'] > 0)
                                    · {{ __('field.return_damaged_units') }}
                                    {{ number_format((int) $r['goods']['damaged_qty']) }}
                                @endif
                            </div>
                        @endif
                    </td>
                    <td>
                        <a class="btn sm gold" href="{{ route('erp.repclose.show', $rep) }}">🤝 {{ __('settle.settle_now') }}</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

{{-- ═══ آخر التصفيات — للمراجعة وإعادة طباعة المحضر ═══ --}}
<div class="card">
    <h3>🗂️ {{ __('settle.recent') }}</h3>
    <div class="tablewrap st-tbl">
        <table>
            <tr>
                <th>#</th>
                <th>{{ __('settle.rep') }}</th>
                {{-- النافذة كاملة من→إلى بدل «إلى» بس (طلب المالك ١١/٨) --}}
                <th>{{ __('settle.window') }}</th>
                <th>{{ __('settle.cash_sales') }}</th>
                <th>{{ __('settle.credit_sales') }}</th>
                <th>{{ __('settle.collections') }}</th>
                <th>{{ __('settle.expected') }}</th>
                <th>{{ __('settle.received') }}</th>
                <th>{{ __('settle.balance') }}</th>
                <th>{{ __('settle.by') }}</th>
                <th data-nosum></th>
            </tr>
            @forelse ($recent as $s)
                <tr>
                    <td class="num"><b>{{ $s->number }}</b></td>
                    <td>{{ $s->user?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:10.5px" dir="ltr">
                        {{ $s->from_at?->format('m-d h:i A') ?? __('settle.since_start') }}
                        ← {{ $s->to_at->format('m-d h:i A') }}
                    </td>
                    <td class="num">{{ $fmt($s->cash_sales) }}</td>
                    {{-- ⚠️ آجل مريم كان مخزون هنا وماكانش بيبان في أي حتة --}}
                    <td class="num mid"><b>{{ $fmt($s->credit_sales) }}</b></td>
                    <td class="num">{{ $fmt($s->cash_collections) }}</td>
                    <td class="num">{{ $fmt($s->expected) }}</td>
                    <td class="num pos"><b>{{ $fmt($s->received) }}</b></td>
                    <td>
                        <span class="badge {{ $s->balanceClass() }}">
                            {{ $s->balanceLabel() }}@if ((float) $s->balance != 0) {{ $fmt(abs((float) $s->balance)) }}@endif
                        </span>
                    </td>
                    <td class="s">{{ $s->creator?->name ?? '—' }}</td>
                    <td style="white-space:nowrap">
                        {{-- تفاصيل التصفية — كل رقم في المحضر جاي منين (١١/٨ مساءً) --}}
                        <a class="btn sm" href="{{ route('erp.repclose.details', $s) }}"
                           title="{{ __('settle.details_title') }}">🔎</a>
                        <a class="btn sm" href="{{ route('erp.repclose.doc', $s) }}">🖨️</a>
                        {{-- مسح — أدمن، وآخر تصفية للمندوب بس (سلامة سلسلة الأرصدة) --}}
                        @if (auth()->user()->isAdmin() && ($latestIds[$s->user_id] ?? null) === $s->id)
                            <form method="POST" action="{{ route('erp.repclose.destroy', $s) }}" style="display:inline"
                                  onsubmit="return confirm(@js(__('settle.delete_confirm', ['number' => $s->number])))">
                                @csrf @method('DELETE')
                                <button class="btn sm red" type="submit">🗑</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">{{ __('settle.no_settlements') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection

@section('scripts')
<style>
.st-tbl th, .st-tbl td { text-align: center; vertical-align: middle; }
</style>
@endsection
