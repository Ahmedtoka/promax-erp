@extends('layouts.system')

{{--
    سجل الأصول الثابتة (Lane 4، L4-D10..D12) — التكلفة، مجمع الإهلاك،
    القيمة الدفترية والقسط الشهري. الأصل المشترى بيتولد من سند مصروف
    بند «أصل» لما يتدفع؛ «أصل افتتاحي» هنا لأصل موجود قبل النظام
    (صف من غير قيد). «تشغيل الإهلاك» = `promax:depreciate` (أدمن بس).
--}}

@section('title', __('cash.assets'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $canRun = \App\Support\Access::action(auth()->user(), 'act.gl.admin');
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    <a class="btn sm" href="{{ route('gl.expenses') }}">🧾 {{ __('gl.expenses') }}</a>
    @if ($canRun)
        <button class="btn sm" type="button" onclick="openDlg('dlgDepr')">📉 {{ __('cash.depr_run') }}</button>
    @endif
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgAssetNew')">＋ {{ __('cash.asset_new') }}</button>
    @endif
@endsection

@section('content')

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('cash.assets_n') }}</div>
        <div class="val">{{ number_format($kpis['n']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cash.asset_cost') }}</div>
        <div class="val" dir="ltr">{{ $fmt($kpis['cost']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cash.asset_accum') }}</div>
        <div class="val" dir="ltr">{{ $fmt($kpis['accum']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cash.asset_book') }}</div>
        <div class="val pos" dir="ltr">{{ $fmt($kpis['book']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cash.assets_next', ['month' => $month]) }}</div>
        <div class="val" dir="ltr">{{ $fmt($kpis['next']) }}</div>
        <div class="sub2">{{ __('cash.assets_next_sub') }}</div>
    </div>
</div>

<div class="card" id="assets-table">
    <h3>🖥️ {{ __('cash.assets') }} <span class="side">{{ __('cash.assets_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('cash.exp_category') }}</span>
            <select name="category" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_asset_categories') }}</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->id }}" @selected((string) request('category') === (string) $c->id)>{{ $c->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('cash.cost_center') }}</span>
            <select name="cost_center_id" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_cost_centers') }}</option>
                @foreach ($costCenters as $cc)
                    <option value="{{ $cc->id }}" @selected((string) request('cost_center_id') === (string) $cc->id)>{{ $cc->code }} · {{ $cc->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('cash.asset_status') }}</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_statuses') }}</option>
                @foreach (\App\Models\FixedAsset::STATUSES as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('cash.asset_status_'.$st) }}</option>
                @endforeach
            </select></label>
        {{-- التاريخ على بداية الإهلاك — الفاضي = كل الأصول --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.assets') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('cash.code') }}</th>
                <th style="text-align:start">{{ __('cash.name') }}</th>
                <th style="text-align:start">{{ __('cash.exp_category') }}</th>
                <th data-nosum>{{ __('cash.asset_expense') }}</th>
                <th style="text-align:start">{{ __('cash.cost_center') }}</th>
                <th data-nosum>{{ __('cash.asset_start') }}</th>
                <th class="num" data-nosum>{{ __('cash.asset_life') }}</th>
                <th class="num">{{ __('cash.asset_cost') }}</th>
                <th class="num">{{ __('cash.asset_monthly') }}</th>
                <th class="num">{{ __('cash.asset_accum') }}</th>
                <th class="num">{{ __('cash.asset_book') }}</th>
                <th>{{ __('cash.asset_status') }}</th>
            </tr>
            @forelse ($rows as $r)
                @php $a = $r['asset']; @endphp
                <tr @if ($a->status !== 'active') style="opacity:.6" @endif>
                    <td><b dir="ltr">{{ $a->code }}</b></td>
                    <td style="text-align:start">{{ $a->displayName() }}
                        @if ((float) $a->opening_accum > 0 || $a->opening_as_of)
                            <span class="badge b-purple">{{ __('cash.asset_opening') }}</span>
                        @endif
                    </td>
                    <td style="text-align:start">{{ $a->category?->displayName() ?? '—' }}</td>
                    <td dir="ltr">{{ $a->expense?->number ?? '—' }}</td>
                    <td style="text-align:start">{{ $a->costCenter?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:11px">{{ $a->start_date?->format('Y-m-d') }}</td>
                    <td class="num">{{ $a->life_months }}</td>
                    <td class="num">{{ $fmt($a->cost) }}</td>
                    <td class="num">{{ $fmt($r['monthly']) }}</td>
                    <td class="num">{{ $fmt($r['accum']) }}</td>
                    <td class="num"><b>{{ $fmt($r['book']) }}</b></td>
                    <td><span class="badge {{ $a->status === 'active' ? 'b-green' : 'b-gray' }}">{{ __('cash.asset_status_'.$a->status) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="12" style="color:var(--muted);font-size:12px">{{ __('cash.assets_none') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@if ($canPost)
<dialog id="dlgAssetNew">
    <form class="dlg" method="POST" action="{{ route('gl.assets.store') }}">
        @csrf
        <h4>🖥️ {{ __('cash.asset_new') }}</h4>
        <div class="alert info" style="font-size:11.5px"><span>ℹ️</span><span>{{ __('cash.asset_new_hint') }}</span></div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.name') }} <b class="req-star">*</b></label>
                <input type="text" name="name" required maxlength="150" value="{{ old('name') }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.name_en') }}</label>
                <input type="text" name="name_en" maxlength="150" dir="ltr" value="{{ old('name_en') }}" style="width:100%">
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.exp_category') }} <b class="req-star">*</b></label>
                <select name="category_id" required style="width:100%">
                    <option value="">{{ __('ui.choose', ['x' => __('cash.exp_category')]) }}</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('cash.cost_center') }}</label>
                <select name="cost_center_id" style="width:100%">
                    <option value="">—</option>
                    @foreach ($costCenters as $cc)
                        <option value="{{ $cc->id }}" @selected(old('cost_center_id') == $cc->id)>{{ $cc->code }} · {{ $cc->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.asset_cost') }} <b class="req-star">*</b></label>
                <input type="number" name="cost" required min="0.01" step="0.01" dir="ltr" value="{{ old('cost') }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.asset_salvage') }}</label>
                <input type="number" name="salvage" min="0" step="0.01" dir="ltr" value="{{ old('salvage') }}" style="width:100%">
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.asset_life') }} <b class="req-star">*</b></label>
                <input type="number" name="life_months" required min="1" max="600" step="1" dir="ltr" value="{{ old('life_months') }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.asset_start') }} <b class="req-star">*</b></label>
                <input type="date" name="start_date" required value="{{ old('start_date') }}" style="width:100%">
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.asset_opening_accum') }}</label>
                <input type="number" name="opening_accum" min="0" step="0.01" dir="ltr" value="{{ old('opening_accum') }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.asset_opening_as_of') }}</label>
                <input type="date" name="opening_as_of" value="{{ old('opening_as_of') }}" style="width:100%">
                <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.asset_opening_hint') }}</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgAssetNew')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@if ($canRun)
<dialog id="dlgDepr">
    <form class="dlg" method="POST" action="{{ route('gl.assets.depreciate') }}" onsubmit="return confirm(@js(__('cash.confirm_depr')))">
        @csrf
        <h4>📉 {{ __('cash.depr_run') }}</h4>
        <div style="font-size:11.5px;color:var(--muted)">{{ __('cash.depr_hint') }}</div>
        <div style="margin-top:10px">
            <label class="f">{{ __('cash.depr_month') }} <b class="req-star">*</b></label>
            <input type="month" name="month" required value="{{ old('month', $month) }}" style="width:100%">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgDepr')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('cash.depr_run') }}</button>
        </div>
    </form>
</dialog>
@endif

<script>
@if ($errors->any() && old('name') && $canPost)
    openDlg('dlgAssetNew');
@endif
</script>

@endsection
