@extends('layouts.system')

@section('title', __('online.sync_title'))

@php
    $isAdmin = auth()->user()->role === 'admin';
    $canAct = in_array(auth()->user()->role, ['admin', 'manager'], true);
    $money = fn ($v) => number_format((float) $v, 2);
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

{{-- ═══ إعدادات شوبيفاي — أدمن بس، وبتظهر مفتوحة لو الربط ناقص ═══ --}}
@if ($isAdmin)
    <details class="card" style="margin-bottom:12px" @if (! $ready || ! ($settings['online_warehouse_id'] ?? '')) open @endif>
        <summary style="cursor:pointer;font-weight:900">⚙️ {{ __('online.settings') }}
            @if (! $ready)
                <span class="badge b-red">{{ __('online.not_configured') }}</span>
            @endif
        </summary>
        <form method="POST" action="{{ route('online.sync.settings') }}" style="margin-top:12px">
            @csrf
            <div class="frow">
                <div>
                    <label class="f">{{ __('online.shop_domain') }}</label>
                    <input name="shopify_domain" value="{{ $settings['shopify_domain'] ?? '' }}"
                           placeholder="xxxx.myshopify.com" dir="ltr">
                </div>
                <div>
                    <label class="f">{{ __('online.admin_token') }}</label>
                    {{-- ⚠️ بتتعرض فاضية دايماً — التوكن مايتطبعش في الصفحة.
                         سيبها فاضية = مفيش تغيير --}}
                    <input name="shopify_admin_token" value="" type="password" autocomplete="new-password"
                           placeholder="{{ $ready ? '••••••••' : 'shpat_...' }}" dir="ltr">
                </div>
                <div>
                    <label class="f">{{ __('online.api_version') }}</label>
                    <input name="shopify_api_version" value="{{ $settings['shopify_api_version'] ?? '2025-01' }}" dir="ltr">
                </div>
                <div>
                    <label class="f">{{ __('online.warehouse') }}</label>
                    <select name="online_warehouse_id">
                        <option value="">—</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected((string) $w->id === ($settings['online_warehouse_id'] ?? ''))>
                                {{ $w->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="dash-hint" style="margin:6px 0 10px">{{ __('online.settings_hint') }}</div>
            <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
        </form>
    </details>
@endif

{{-- ═══ الهيدر: العدادات + زرار السينك ═══ --}}
<div class="kpis">
    <div class="kpi"><b class="num">{{ $counts['new'] }}</b><span>{{ __('online.k_new') }}</span></div>
    <div class="kpi"><b class="num">{{ $counts['postponed'] }}</b><span>{{ __('online.k_postponed') }}</span></div>
    <div class="kpi"><b class="num {{ $counts['due_today'] > 0 ? 'mid' : '' }}">{{ $counts['due_today'] }}</b>
        <span>{{ __('online.k_due_today') }}</span></div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
        <h3 style="margin:0">🔄 {{ __('online.sync_title') }}</h3>
        @if ($canAct)
            <form method="POST" action="{{ route('online.sync.run') }}">
                @csrf
                <button class="btn gold" type="submit" @disabled(! $ready)>
                    🔄 {{ __('online.sync_btn') }}</button>
            </form>
        @endif
    </div>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.sync_hint') }}</div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('online.area') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th>{{ __('online.wants') }}</th>
                <th class="num">{{ __('online.amount') }}</th>
                <th class="num">{{ __('online.shipping') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($orders as $o)
                <tr @if ($o->status === 'postponed' && $o->postponed_to?->lte(today())) style="background:#FFF8EC" @endif>
                    <td class="num s"><b>#{{ $o->number }}</b>
                        @if ($o->ordered_at)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $o->ordered_at->format('d/m h:i A') }}</span>
                        @endif
                    </td>
                    <td>{{ $o->customer_name ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $o->phone ?: '—' }}</td>
                    <td class="s">{{ $o->area ?: '—' }}</td>
                    <td class="num">{{ $o->items_count }}</td>
                    <td style="max-width:260px">
                        @foreach ($o->items as $i)
                            <div style="font-size:11px">
                                {{ $i->qty }} × {{ $i->product?->displayName() ?? $i->title }}
                                @if ($i->product_id === null)
                                    <span class="badge b-red" style="font-size:9px">{{ __('online.unlinked') }}</span>
                                @endif
                            </div>
                        @endforeach
                    </td>
                    <td class="num">{{ $money($o->subtotal) }}</td>
                    <td class="num">{{ $money($o->shipping) }}</td>
                    <td class="num"><b>{{ $money($o->total) }}</b></td>
                    <td>
                        <span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span>
                        @if ($o->status === 'postponed' && $o->postponed_to)
                            <br><span style="font-size:10.5px;color:var(--muted)">📅 {{ $o->postponed_to->format('Y-m-d') }}</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($canAct)
                            <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end">
                                <form method="POST" action="{{ route('online.confirm', $o) }}"
                                      onsubmit="return confirm(CONFIRM_MSG)">
                                    @csrf
                                    <button class="btn sm green" type="submit"
                                            @disabled($o->hasUnmatchedItems())
                                            @if ($o->hasUnmatchedItems()) title="{{ __('online.unlinked_hint') }}" @endif>
                                        ✅ {{ __('online.act_confirm') }}</button>
                                </form>
                                <button class="btn sm" type="button"
                                        onclick="openPostpone({{ $o->id }}, '{{ $o->number }}')">
                                    ⏳ {{ __('online.act_postpone') }}</button>
                                <button class="btn sm red" type="button"
                                        onclick="openCancel({{ $o->id }}, '{{ $o->number }}')">
                                    ✖ {{ __('online.act_cancel') }}</button>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('online.sync_empty') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $orders])
</div>

{{-- ═══ ديالوج التأجيل ═══ --}}
<dialog id="dlgPostpone">
    <form class="dlg" method="POST" id="formPostpone">
        @csrf
        <h4>⏳ {{ __('online.postpone_title') }} <span id="ppNum"></span></h4>
        <label class="f">{{ __('online.postpone_date') }}</label>
        <input type="date" name="postponed_to" required min="{{ today()->toDateString() }}"
               style="width:100%;margin-bottom:12px">
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgPostpone')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">⏳ {{ __('online.act_postpone') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ ديالوج الإلغاء ═══ --}}
<dialog id="dlgCancel">
    <form class="dlg" method="POST" id="formCancel">
        @csrf
        <h4>✖ {{ __('online.cancel_title') }} <span id="ccNum"></span></h4>
        <label class="f">{{ __('online.cancel_reason') }}</label>
        <input name="reason" required maxlength="250" style="width:100%;margin-bottom:12px">
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgCancel')">{{ __('common.cancel') }}</button>
            <button class="btn red" type="submit">✖ {{ __('online.act_cancel') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<script>
    const CONFIRM_MSG = @js(__('online.confirm_msg'));
    const POSTPONE_URL = @js(url('erp/online/orders'));

    function openPostpone(id, num) {
        document.getElementById('formPostpone').action = POSTPONE_URL + '/' + id + '/postpone';
        document.getElementById('ppNum').textContent = '#' + num;
        openDlg('dlgPostpone');
    }

    function openCancel(id, num) {
        document.getElementById('formCancel').action = POSTPONE_URL + '/' + id + '/cancel';
        document.getElementById('ccNum').textContent = '#' + num;
        openDlg('dlgCancel');
    }
</script>
@endsection
