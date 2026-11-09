@extends('layouts.system')

@section('title', __('review.tab_stock'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $canReset = \App\Support\Access::action(auth()->user(), 'act.review.stock_reset');
@endphp

@section('content')

@include('erp.review._tabs')

<div class="card">
    <h3>🗓️ {{ __('review.stock_steps') }}</h3>
    <ol style="margin:6px 22px 0;line-height:2">
        @foreach (range(1, 6) as $i)<li>{{ __('review.step'.$i) }}</li>@endforeach
    </ol>
</div>

<div class="card">
    <h3>🏭 {{ __('review.warehouses') }}</h3>
    <div class="tablewrap">
        <table>
            <thead><tr>
                <th>{{ __('review.col_warehouse') }}</th><th>{{ __('review.col_current') }}</th>
                <th>{{ __('review.item_zeroed') }}</th><th>{{ __('review.col_entered') }}</th><th>{{ __('review.item_entered') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($warehouses as $w)
                @php $ch = $checks['warehouse'][$w->id] ?? []; $s = $stock->get($w->id); $en = $entered->get($w->id); @endphp
                <tr data-row="warehouse-{{ $w->id }}">
                    <td><b>{{ $w->displayName() }}</b><div class="rv-sub" dir="ltr">{{ $w->code }}</div></td>
                    <td class="num"><b>{{ __('review.pieces', ['n' => $fmt($s->q ?? 0)]) }}</b><div class="rv-sub">{{ __('review.col_batches', ['n' => (int) ($s->batches ?? 0)]) }}</div></td>
                    <td>
                        @if ($canReset && (int) ($s->q ?? 0) > 0)
                            <form method="POST" action="{{ route('erp.review.stock.zero', $w) }}"
                                  onsubmit="const v = prompt(@js(__('review.zero_confirm', ['wh' => $w->displayName(), 'code' => $w->code]))); if (v === null) return false; this.confirm.value = v; return true;">
                                @csrf <input type="hidden" name="confirm">
                                <button class="btn sm red" type="submit">🧹 {{ __('review.zero') }}</button>
                            </form>
                        @endif
                        @include('erp.review._mark', ['type' => 'warehouse', 'id' => $w->id, 'item' => 'zeroed', 'check' => $ch['zeroed'] ?? null])
                    </td>
                    <td class="num">
                        {{ __('review.pieces', ['n' => $fmt($en->q ?? 0)]) }}<div class="rv-sub">{{ (int) ($en->n ?? 0) }} ×</div>
                        @if ($canReset)
                            <button type="button" class="btn sm gold" onclick="rvBatch({{ $w->id }}, @js($w->displayName()))">{{ __('review.add_batch') }}</button>
                        @endif
                    </td>
                    <td>@include('erp.review._mark', ['type' => 'warehouse', 'id' => $w->id, 'item' => 'entered', 'check' => $ch['entered'] ?? null])</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>🚚 {{ __('review.reps') }}</h3>
    <div class="tablewrap">
        <table>
            <thead><tr>
                <th>{{ __('review.col_rep') }}</th><th>{{ __('review.col_custody') }}</th>
                <th>{{ __('review.item_zeroed') }}</th><th>{{ __('review.item_issued') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($reps as $u)
                @php $ch = $checks['rep'][$u->id] ?? []; @endphp
                <tr data-row="rep-{{ $u->id }}">
                    <td><b>{{ $u->displayName() }}</b><div class="rv-sub">{{ $u->code }}</div></td>
                    <td class="num"><b>{{ __('review.pieces', ['n' => $fmt($repQty[$u->id] ?? 0)]) }}</b></td>
                    <td>
                        @if (\App\Support\Access::allows(auth()->user(), 'ops.rep'))
                            <a class="btn sm rv-edit" href="{{ route('ops.rep', $u) }}" target="_blank" rel="noopener">{{ __('review.rep_page') }}</a>
                        @endif
                        @include('erp.review._mark', ['type' => 'rep', 'id' => $u->id, 'item' => 'zeroed', 'check' => $ch['zeroed'] ?? null])
                    </td>
                    <td>
                        @if (\App\Support\Access::allows(auth()->user(), 'ops.handout'))
                            <a class="btn sm rv-edit" href="{{ route('ops.handout') }}" target="_blank" rel="noopener">{{ __('review.handout') }}</a>
                        @endif
                        @include('erp.review._mark', ['type' => 'rep', 'id' => $u->id, 'item' => 'issued', 'check' => $ch['issued'] ?? null])
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:20px">{{ __('review.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>📜 {{ __('review.moves') }}</h3>
    <div class="tablewrap" style="max-height:420px;overflow-y:auto">
        <table>
            <thead><tr>
                <th>{{ __('review.col_when') }}</th><th>{{ __('review.col_warehouse') }}</th><th>{{ __('review.product') }}</th>
                <th>{{ __('review.batch_no') }}</th><th>{{ __('review.expires_on') }}</th><th>{{ __('review.qty') }}</th><th>{{ __('review.col_who') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($moves as $mv)
                <tr>
                    <td>{{ $mv->created_at?->format('Y-m-d H:i') }} <span class="badge {{ $mv->kind === 'zero' ? 'b-red' : 'b-green' }}">{{ __('review.kind_'.$mv->kind) }}</span></td>
                    <td>{{ $mv->warehouse?->displayName() }}</td>
                    <td>{{ $mv->product?->displayName() }}</td>
                    <td dir="ltr">{{ $mv->batch_no }}</td>
                    <td>{{ $mv->expires_on?->toDateString() }}</td>
                    <td class="num">{{ $mv->kind === 'zero' ? '−' : '+' }}{{ $fmt($mv->qty) }}</td>
                    <td>{{ $mv->user?->displayName() }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">{{ __('review.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($canReset)
<dialog id="dlgBatch">
    <form class="dlg" method="POST" id="fBatch">
        @csrf
        <h4 id="bTitle"></h4>
        @if ($errors->any())<div class="alert error" style="margin-bottom:10px">{{ $errors->first() }}</div>@endif
        <div class="frow">
            <div style="grid-column:1/-1"><label class="f">{{ __('review.product') }}</label>
                <select name="product_id" required style="width:100%">
                    <option value="">—</option>
                    @foreach ($products as $p)<option value="{{ $p->id }}" @selected((int) old('product_id') === $p->id)>{{ $p->code }} — {{ $p->displayName() }}</option>@endforeach
                </select></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('review.batch_no') }}</label><input type="text" name="batch_no" required maxlength="60" dir="ltr" style="width:100%" value="{{ old('batch_no') }}"></div>
            <div><label class="f">{{ __('review.expires_on') }}</label><input type="date" name="expires_on" required style="width:100%" value="{{ old('expires_on') }}"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('review.qty') }}</label><input type="number" name="qty" min="1" required style="width:100%"></div>
            <div><label class="f">{{ __('review.cost') }}</label><input type="number" name="cost" step="0.01" min="0" style="width:100%"></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgBatch')">{{ __('review.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('review.save') }}</button>
        </div>
    </form>
</dialog>
<script>
function rvBatch(id, name) {
    document.getElementById('fBatch').action = @js(url('/erp/review/stock')) + '/' + id + '/batch';
    document.getElementById('bTitle').textContent = @js(__('review.add_batch_title', ['wh' => '__W__'])).replace('__W__', name);
    sessionStorage.setItem('rvBatchWh', JSON.stringify([id, name]));
    openDlg('dlgBatch');
}
// بعد الحفظ بنفتح الديالوج تاني لنفس المخزن — الإدخال صنف ورا صنف
document.addEventListener('DOMContentLoaded', () => {
    const last = sessionStorage.getItem('rvBatchWh');
    if (last && (@js(session()->has('ok')) || @js($errors->any()))) { const [id, name] = JSON.parse(last); rvBatch(id, name); }
});
</script>
@endif

@endsection
