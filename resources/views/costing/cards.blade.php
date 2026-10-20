@extends('layouts.system')

@section('title', __('costing.cards_title'))

@section('content')
@php
    $canAdmin = \App\Support\Access::action(auth()->user(), 'act.costing.admin');
    $m2 = fn ($v) => number_format((float) $v, 2);
    $m4 = fn ($v) => number_format((float) $v, 4);
    $badge = ['ok' => 'b-green', 'near' => 'b-orange', 'above' => 'b-red', 'none' => 'b-gray'];
@endphp

<div class="card">
    <h3>🧮 {{ __('costing.cards_title') }}</h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('costing.cards_hint') }}</div>
    <div style="display:flex;gap:8px;margin-bottom:10px">
        <a class="btn {{ $tab === 'cards' ? 'gold' : '' }}" href="{{ route('costing.cards') }}">{{ __('costing.tab_cards') }}</a>
        <a class="btn {{ $tab === 'history' ? 'gold' : '' }}" href="{{ route('costing.cards', ['tab' => 'history']) }}">{{ __('costing.tab_history') }}</a>
    </div>
    @if ($errors->any())<div class="alert">{{ $errors->first() }}</div>@endif
</div>

@if ($tab === 'cards')
    @if ($zero > 0)
        <div class="alert warn" style="border-inline-start:4px solid var(--orange)">⚠️ {{ __('costing.zero_cost_warning', ['n' => $zero]) }}</div>
    @endif
    <div class="card">
        <form class="searchbar" method="GET" data-noprint>
            <select name="status">
                <option value="">{{ __('costing.all_statuses') }}</option>
                @foreach (['ok', 'near', 'above', 'none'] as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ __('costing.status_'.$s) }}</option>
                @endforeach
            </select>
            <select name="family">
                <option value="">{{ __('costing.all_families') }}</option>
                @foreach ($families as $f)
                    <option value="{{ $f->key }}" @selected($family === $f->key)>{{ \App\Models\ProductFamily::label($f->key) }}</option>
                @endforeach
            </select>
            <button class="btn gold">{{ __('common.search') }}</button>
            <a class="btn" href="{{ route('costing.cards') }}">{{ __('common.clear') }}</a>
            <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
            <span class="badge b-gray">{{ __('ui.rows_n', ['n' => $cards->count()]) }}</span>
        </form>
        <div class="tablewrap">
            <table>
                <tr>
                    <th data-nosum>{{ __('costing.col_product') }}</th>
                    <th data-nosum>{{ __('costing.col_family') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_raw') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_packaging') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_overhead') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_unit_batch') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_unit_wavg') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_unit') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_suggested') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_tp') }}</th>
                    <th data-nosum>{{ __('costing.col_status') }}</th>
                    @if ($canAdmin)<th data-nosum></th>@endif
                </tr>
                @forelse ($cards as $r)
                    @php $p = $r['product']; @endphp
                    <tr>
                        <td>
                            <details>
                                <summary>{{ $p->displayName() }}
                                    @if ($r['bom'] === null)<span class="badge b-gray">{{ __('costing.no_bom') }}</span>@endif
                                    @if ($r['unit'] == 0)<span class="badge b-red">{{ __('costing.flag_zero_cost') }}</span>@endif
                                </summary>
                                @if ($r['lines'])
                                    <table>
                                        <tr><th data-nosum>{{ __('costing.col_product') }}</th><th class="num" data-nosum>{{ __('costing.col_qty') }}</th><th class="num" data-nosum>{{ __('costing.col_last_price') }}</th><th class="num" data-nosum>{{ __('costing.col_avg_price') }}</th><th class="num" data-nosum>{{ __('costing.col_value') }}</th></tr>
                                        @foreach ($r['lines'] as $l)
                                            <tr><td>{{ $l['product']->displayName() }}</td><td class="num">{{ rtrim(rtrim(number_format($l['qty'], 5, '.', ''), '0'), '.') ?: '0' }}</td><td class="num">{{ $m4($l['last_price']) }}</td><td class="num">{{ $m4($l['avg_price']) }}</td><td class="num">{{ $m4($l['value']) }}</td></tr>
                                        @endforeach
                                    </table>
                                @endif
                                <div class="s" style="color:var(--muted)">{{ __('costing.col_overhead') }}: {{ __('costing.basis_'.$r['overhead_basis']) }}</div>
                            </details>
                        </td>
                        <td>{{ $p->familyLabel() }}</td>
                        <td class="num">{{ $m4($r['raw']) }}</td>
                        <td class="num">{{ $m4($r['packaging']) }}</td>
                        <td class="num">{{ $m4($r['overhead']) }}</td>
                        <td class="num">{{ $m4($r['unit_batch']) }}</td>
                        <td class="num">{{ $m4($r['unit_wavg']) }}</td>
                        <td class="num"><b>{{ $m4($r['unit']) }}</b></td>
                        <td class="num">{{ $m2($r['suggested']) }}</td>
                        <td class="num">{{ $r['tp'] ? $m2($r['tp']->price) : '—' }}</td>
                        <td><span class="badge {{ $badge[$r['status']] }}">{{ __('costing.status_'.$r['status']) }}</span></td>
                        @if ($canAdmin)
                            <td><button class="btn sm" type="button"
                                onclick="openApprove({{ \Illuminate\Support\Js::from($p->displayName()) }}, {{ $r['suggested'] }}, {{ \Illuminate\Support\Js::from(route('costing.cards.approve', $p)) }})">{{ __('costing.approve_btn') }}</button></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:28px">{{ __('costing.no_cards') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    @if ($canAdmin)
    <dialog id="dlgApprove">
        <form class="dlg" method="POST" id="frmApprove" action="#">
            @csrf
            <h4>{{ __('costing.approve_title') }} <span id="apName"></span></h4>
            <div class="frow">
                <div><label class="f">{{ __('costing.price_label') }}</label><input type="number" name="price" id="apPrice" step="0.01" min="0.01" required></div>
                <div><label class="f">{{ __('costing.note_label') }}</label><input type="text" name="note" maxlength="255"></div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
                <button class="btn" type="button" onclick="closeDlg('dlgApprove')">{{ __('costing.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('costing.approve_btn') }}</button>
            </div>
        </form>
    </dialog>
    <script>
        function openApprove(name, price, url) {
            document.getElementById('frmApprove').action = url;
            document.getElementById('apName').textContent = name;
            document.getElementById('apPrice').value = price;
            openDlg('dlgApprove');
        }
    </script>
    @endif
@else
    <div class="card">
        <form class="searchbar" method="GET" data-noprint>
            <input type="hidden" name="tab" value="history">
            @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
            <button class="btn gold">{{ __('common.search') }}</button>
            <a class="btn" href="{{ route('costing.cards', ['tab' => 'history']) }}">{{ __('common.clear') }}</a>
            <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
            <span class="badge b-gray">{{ __('ui.rows_n', ['n' => $rows->count()]) }}</span>
        </form>
        <div class="tablewrap">
            <table>
                <tr>
                    <th data-nosum>{{ __('costing.col_product') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_tp') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_unit_cost') }}</th>
                    <th class="num" data-nosum>{{ __('costing.col_markup') }}</th>
                    <th data-nosum>{{ __('costing.col_effective') }}</th>
                    <th data-nosum>{{ __('costing.col_approved_by') }}</th>
                    <th data-nosum>{{ __('costing.col_approved_at') }}</th>
                    <th data-nosum>{{ __('costing.col_note') }}</th>
                </tr>
                @forelse ($rows as $t)
                    <tr>
                        <td>{{ $t->product?->displayName() }}</td>
                        <td class="num"><b>{{ $m2($t->price) }}</b></td>
                        <td class="num">{{ $m4($t->unit_cost) }}</td>
                        <td class="num">{{ $t->markup_pct !== null ? $m2($t->markup_pct) : '—' }}</td>
                        <td class="num">{{ $t->effective_from?->format('Y-m-d') }}</td>
                        <td>{{ $t->approver?->name }}</td>
                        <td class="num">{{ $t->approved_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $t->note }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">{{ __('costing.no_history') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>
@endif
@endsection
