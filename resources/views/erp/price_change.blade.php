@extends('layouts.system')

{{--
    معاينة / نتيجة تعديل أسعار (Lane 13).

    ⚠️ المسودة مابتغيّرش أي حاجة — الأرقام هنا من معاينة اتعملت جوه
    ترانزاكشن ورجعت. «تأكيد» بيعيد نفس الحساب ويرفض لو الأرقام اختلفت.
    ⚠️ مفيش كويري هنا — كل حاجة جاية من الكنترولر.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $sign = fn ($n) => (float) $n > 0.004 ? 'neg' : ((float) $n < -0.004 ? 'pos' : '');
    $totals = $result['totals'] ?? [];
    $zero = ['before_net' => 0, 'before_tax' => 0, 'before_grand' => 0, 'after_net' => 0, 'after_tax' => 0, 'after_grand' => 0, 'diff_net' => 0, 'diff_tax' => 0, 'diff_grand' => 0];
    $rows = [
        'row_invoices' => $totals['invoice'] ?? $zero,
        'row_pos' => $totals['po'] ?? $zero,
        'row_all' => $totals['all'] ?? $zero,
    ];
    $items = $result['item_changes'] ?? ($run->item_changes ?? []);
    $isListItems = $run->trigger === 'list' || $run->trigger === 'invoice';
    $statusBadge = match ($run->status) {
        'draft' => 'b-orange',
        'applied' => 'b-green',
        'master_only' => 'b-blue',
        default => 'b-gray',
    };
    $payLabel = fn ($p) => $p === null ? '—'
        : (\Illuminate\Support\Facades\Lang::has('enums.payment.'.$p) ? __('enums.payment.'.$p) : $p);
    $docUrl = fn ($d) => $d['doc_type'] === 'invoice'
        ? route('ops.invoice', $d['doc_id'])
        : route('ops.pos.show', $d['doc_id']);
    $showBranchAsk = $run->isDraft() && $chain !== null && $chain['list'] !== null
        && (int) $chain['list']->id !== (int) $run->price_list_id && $chain['total'] > 1;
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $jsApply = json_encode(__('reprice.confirm_apply', [
        'inv' => (int) ($result['invoices_changed'] ?? 0),
        'po' => (int) ($result['pos_changed'] ?? 0),
        'diff' => $fmt($totals['all']['diff_grand'] ?? 0),
    ]), $jsFlags);
    $jsMaster = json_encode(__('reprice.confirm_master'), $jsFlags);
    $jsCancel = json_encode(__('reprice.confirm_cancel'), $jsFlags);
@endphp

@section('title', __('reprice.preview_title').' — '.$run->number)

@section('actions')
    @if ($canLog)
        <a class="btn" href="{{ route('erp.price_changes') }}">🧾 {{ __('reprice.log_title') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🏷️ {{ $run->number }} <span class="badge {{ $statusBadge }}">{{ $run->statusLabel() }}</span>
        <span class="side">{{ $run->triggerLabel() }}@if ($target) — {{ $target }}@endif</span></h3>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span class="badge b-gray">{{ __('reprice.meta_scope') }}: {{ __('reprice.scope_'.$run->scope) }}</span>
        <span class="badge b-gray">{{ __('reprice.meta_by') }}: {{ $run->user?->displayName() ?? '—' }}</span>
        <span class="badge b-gray">{{ __('reprice.meta_created') }}: {{ $run->created_at?->format('Y-m-d H:i') }}</span>
        <span class="badge b-gray">{{ __('reprice.meta_since') }}: {{ $run->since?->toDateString() ?? __('reprice.all_dates') }}</span>
        @if ($run->priceList && $run->trigger !== 'list')
            <span class="badge b-blue">{{ __('reprice.col_new_list') }}: {{ $run->priceList->displayName() }}</span>
        @endif
    </div>
</div>

@if ($run->isDraft())
    <div class="alert info">{{ __('reprice.draft_note') }}</div>
@elseif ($run->status === 'applied')
    <div class="alert good">{{ __('reprice.applied_note', ['date' => $run->applied_at?->format('Y-m-d H:i')]) }}</div>
@elseif ($run->status === 'master_only')
    <div class="alert info">{{ __('reprice.master_only_note', ['date' => $run->applied_at?->format('Y-m-d H:i')]) }}</div>
@else
    <div class="alert warn">{{ __('reprice.cancelled_note') }}</div>
@endif

@if ($run->isDraft() && $run->error)
    <div class="alert warn">{{ __('reprice.last_error', ['msg' => $run->error]) }}</div>
@endif

@if ($run->isDraft())
    {{-- نطاق الفرع + تاريخ «من» — بيعيدوا المعاينة --}}
    <div class="card">
        <form method="POST" action="{{ route('erp.prices.change.refresh', $run) }}" class="searchbar" style="flex-wrap:wrap">
            @csrf
            @if ($showBranchAsk)
                <div class="alert warn" style="width:100%;margin:0 0 8px">
                    {{ __('reprice.branch_differs', ['list' => $chain['list']->displayName(), 'n' => $chain['total']]) }}
                </div>
                <label style="display:inline-flex;gap:6px;align-items:center">
                    <input type="radio" name="scope" value="branch" @checked($run->scope === 'branch')> {{ __('reprice.branch_only') }}
                </label>
                <label style="display:inline-flex;gap:6px;align-items:center">
                    <input type="radio" name="scope" value="chain" @checked($run->scope === 'chain')> {{ __('reprice.whole_chain', ['n' => $chain['total']]) }}
                </label>
            @endif
            <div>
                <label class="f">{{ __('reprice.since_label') }}</label>
                <input type="date" name="since" value="{{ $run->since?->toDateString() }}" title="{{ __('reprice.since_hint') }}">
            </div>
            <button class="btn" type="submit">{{ __('reprice.btn_refresh') }}</button>
        </form>
    </div>
@endif

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('reprice.k_clients') }}</div><div class="val">{{ (int) ($result['clients'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_invoices') }}</div><div class="val">{{ (int) ($result['invoices_changed'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_pos') }}</div><div class="val">{{ (int) ($result['pos_changed'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_unchanged') }}</div><div class="val">{{ (int) ($result['unchanged'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_skipped') }}</div><div class="val mid">{{ (int) ($result['skipped'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_ledger') }}</div>
        <div class="val {{ $sign($result['ledger_diff'] ?? 0) }}" dir="ltr">{{ $fmt($result['ledger_diff'] ?? 0) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('reprice.ledger_hint') }}</div></div>
</div>

<div class="card">
    <h3>💰 {{ __('reprice.totals_title') }}</h3>
    <div class="tablewrap"><table>
        <thead>
            <tr>
                <th rowspan="2">{{ __('reprice.col_docs') }}</th>
                <th colspan="3">{{ __('reprice.before') }}</th>
                <th colspan="3">{{ __('reprice.after') }}</th>
                <th colspan="3">{{ __('reprice.diff') }}</th>
            </tr>
            <tr>
                @foreach (['before', 'after', 'diff'] as $g)
                    <th class="num">{{ __('reprice.net') }}</th><th class="num">{{ __('reprice.tax') }}</th><th class="num">{{ __('reprice.grand') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $label => $t)
                <tr @if ($label === 'row_all') style="font-weight:800" @endif>
                    <td>{{ __('reprice.'.$label) }}</td>
                    @foreach (['before', 'after'] as $g)
                        @foreach (['net', 'tax', 'grand'] as $k)
                            <td class="num">{{ $fmt($t[$g.'_'.$k] ?? 0) }}</td>
                        @endforeach
                    @endforeach
                    @foreach (['net', 'tax', 'grand'] as $k)
                        <td class="num {{ $sign($t['diff_'.$k] ?? 0) }}" dir="ltr">{{ $fmt($t['diff_'.$k] ?? 0) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3>🏷 {{ __('reprice.items_title') }} <span class="side">{{ count($items) }}</span></h3>
    @if ($items === [])
        <div class="alert info">{{ __('reprice.no_items') }}</div>
    @elseif ($isListItems)
        <div class="tablewrap"><table>
            <thead><tr><th>{{ __('reprice.col_product') }}</th><th class="num">{{ __('reprice.col_old') }}</th><th class="num">{{ __('reprice.col_new') }}</th><th class="num">{{ __('reprice.diff') }}</th></tr></thead>
            <tbody>
                @foreach ($items as $it)
                    <tr>
                        <td>{{ $it['name'] ?? '' }}</td>
                        <td class="num">{{ $fmt($it['old'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($it['new'] ?? 0) }}</td>
                        <td class="num {{ $sign(($it['new'] ?? 0) - ($it['old'] ?? 0)) }}" dir="ltr">{{ $fmt(($it['new'] ?? 0) - ($it['old'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @else
        <div class="tablewrap"><table>
            <thead><tr><th>{{ __('reprice.col_client') }}</th><th>{{ __('reprice.col_old_list') }}</th><th>{{ __('reprice.col_new_list') }}</th></tr></thead>
            <tbody>
                @foreach ($items as $it)
                    <tr>
                        <td><a href="{{ route('erp.clients.show', $it['client_id']) }}">{{ $it['name'] ?? '' }}</a></td>
                        <td>{{ $it['old_list_name'] ?? __('reprice.no_list') }}</td>
                        <td>{{ $it['new_list_name'] ?? __('reprice.no_list') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</div>

<div class="card">
    <h3>📄 {{ __('reprice.docs_title') }} <span class="side">{{ $changed->count() }}</span></h3>
    <div class="tablewrap"><table>
        <thead><tr>
            <th>{{ __('reprice.col_number') }}</th><th>{{ __('reprice.col_type') }}</th><th>{{ __('reprice.col_client') }}</th>
            <th>{{ __('reprice.col_date') }}</th><th>{{ __('reprice.col_payment') }}</th>
            <th class="num">{{ __('reprice.before') }}</th><th class="num">{{ __('reprice.after') }}</th><th class="num">{{ __('reprice.diff') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($changed as $d)
                @php $diff = (float) ($d['after_grand'] ?? 0) - (float) ($d['before_grand'] ?? 0); @endphp
                <tr>
                    <td><a href="{{ $docUrl($d) }}">{{ $d['number'] }}</a></td>
                    <td>{{ __('reprice.type_'.$d['doc_type']) }}</td>
                    <td>{{ $names[$d['client_id']] ?? '—' }}</td>
                    <td>{{ $d['doc_date'] ?? '—' }}</td>
                    <td>{{ $payLabel($d['payment'] ?? null) }}</td>
                    <td class="num">{{ $fmt($d['before_grand'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($d['after_grand'] ?? 0) }}</td>
                    <td class="num {{ $sign($diff) }}" dir="ltr">{{ $fmt($diff) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted)">{{ __('reprice.no_docs') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    @if ($docsTotal > $shownDocs)
        <div class="sub2" style="margin-top:8px">{{ __('reprice.more_docs', ['n' => $docsTotal - $shownDocs]) }}</div>
    @endif
</div>

<div class="card">
    <h3>⏭ {{ __('reprice.skipped_title') }} <span class="side">{{ $skipped->count() }}</span></h3>
    <div class="tablewrap"><table>
        <thead><tr>
            <th>{{ __('reprice.col_number') }}</th><th>{{ __('reprice.col_type') }}</th><th>{{ __('reprice.col_client') }}</th>
            <th>{{ __('reprice.col_date') }}</th><th class="num">{{ __('reprice.grand') }}</th><th>{{ __('reprice.col_reason') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($skipped as $d)
                <tr>
                    <td><a href="{{ $docUrl($d) }}">{{ $d['number'] }}</a></td>
                    <td>{{ __('reprice.type_'.$d['doc_type']) }}</td>
                    <td>{{ $names[$d['client_id']] ?? '—' }}</td>
                    <td>{{ $d['doc_date'] ?? '—' }}</td>
                    <td class="num">{{ $fmt($d['before_grand'] ?? 0) }}</td>
                    <td><span class="badge b-orange">{{ __('reprice.skip_'.$d['reason'], ['detail' => $d['reason_detail'] ?? '']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted)">{{ __('reprice.no_skipped') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
</div>

@if ($run->isDraft())
    <div class="card">
        @unless ($canApply)
            <div class="alert info">{{ __('reprice.apply_admin_only') }}</div>
        @endunless
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if ($canApply)
                <form method="POST" action="{{ route('erp.prices.change.apply', $run) }}"
                      onsubmit="return confirm({!! $jsApply !!})">
                    @csrf
                    <button class="btn green" type="submit">{{ __('reprice.btn_apply') }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('erp.prices.change.master', $run) }}"
                  onsubmit="return confirm({!! $jsMaster !!})">
                @csrf
                <button class="btn gold" type="submit">{{ __('reprice.btn_master') }}</button>
            </form>
            <form method="POST" action="{{ route('erp.prices.change.cancel', $run) }}"
                  onsubmit="return confirm({!! $jsCancel !!})">
                @csrf
                <button class="btn red" type="submit">{{ __('reprice.btn_cancel') }}</button>
            </form>
        </div>
    </div>
@endif

@endsection
