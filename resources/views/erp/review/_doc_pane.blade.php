{{-- لوحة المستند الواحد في مراجعة المستندات (١١/١١) — الورقة قدام السيستم --}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $lockAll = $locks['all'];
    $lockLines = $locks['lines'];
    $editable = $canEdit && $lockAll === [];
    $linesEditable = $editable && $lockLines === [];
    $isInv = $type === 'invoice';
    $grand = (float) $doc->grand_total;
    $u = auth()->user();
    $docUrl = $isInv
        ? (\App\Support\Access::allows($u, 'ops.invoice') ? route('ops.invoice', $doc) : null)
        : (\App\Support\Access::allows($u, 'ops.pos.show') ? route('ops.pos.show', $doc) : null);
    $poEditUrl = ! $isInv && isset($lockLines['po_approved']) && \App\Support\Access::allows($u, 'ops.po.edit') ? route('ops.po.edit', $doc) : null;
    $chain = $client?->group;
    $mother = $chain?->motherCompany;
    $evIcon = ['serial' => '#', 'rep' => '👤', 'date' => '📅'];
@endphp
<div class="dp" data-key="{{ $key }}" data-type="{{ $type }}" data-id="{{ $doc->id }}" data-state="{{ $state }}"
     data-edit="{{ $editable ? 1 : 0 }}" data-lines="{{ $linesEditable ? 1 : 0 }}">

    <header class="dp-head">
        <div>
            <span class="dp-type {{ $type }}">{{ __('review.type_'.$type) }}</span>
            <b class="dp-num" dir="ltr">{{ $doc->number }}</b>
            <span class="chip st-{{ $state }}">{{ __('review.st_'.$state) }}</span>
            @if ($lockAll !== [] || $lockLines !== [])
                <span class="chip st-lock" title="{{ implode(' · ', $lockAll + $lockLines) }}">🔒 {{ __('review.locked') }}</span>
            @endif
        </div>
        @if ($docUrl)
            <a class="dp-open" href="{{ $docUrl }}" target="_blank" rel="noopener">{{ __('review.open_doc') }} ↗</a>
        @endif
    </header>

    <div class="dp-client">
        <b><span dir="ltr">{{ $client?->code }}</span> · {{ $client?->displayName() }}</b>
        <span class="rv-sub">
            {{ $chain ? $chain->displayName() : __('review.solo_group') }}@if ($mother) · {{ $mother->displayName() }}@endif
            · {{ __('review.doc_list') }}: {{ \App\Services\Pricing::listRowFor($client)?->displayName() ?? '—' }}
            · {{ $isInv ? __('review.pay_'.($doc->payment === 'cash' ? 'cash' : 'credit')) : __('review.po_'.(in_array($doc->status, ['pending', 'arrived', 'delivered'], true) ? $doc->status : 'pending')) }}
        </span>
    </div>

    {{-- ═══ العلامة — فوق وثابتة: ✓ مايحتاجش سكرول ═══ --}}
    <div class="dp-bar">
        @if ($state !== 'ok')
            <button type="button" class="btn ok dp-ok">{{ __('review.btn_ok') }} <kbd>Enter</kbd></button>
            <button type="button" class="btn bad dp-issue">{{ __('review.btn_issue') }} <kbd>X</kbd></button>
        @elseif ($canReopen)
            <button type="button" class="btn dp-reopen">↺ {{ __('review.btn_reopen') }}</button>
        @endif
        @if ($check)
            <span class="who">{{ $check->status === 'ok'
                ? __('review.reviewed_by', ['by' => $check->user?->displayName(), 'at' => $check->updated_at?->format('Y-m-d H:i')])
                : __('review.issue_by', ['by' => $check->user?->displayName(), 'at' => $check->updated_at?->format('Y-m-d H:i')]) }}@if ($check->note) — <b>{{ $check->note }}</b>@endif</span>
        @endif
    </div>


    {{-- (١٢/١١) فحص أوتوماتيك قبل ✓ — الأحمر بيطلب Enter تانية للتأكيد --}}
    <div class="dp-checks" aria-label="{{ __('review.checks_title') }}">
        @foreach ($checks as $ck => [$ok, $why])
            <span class="{{ $ok === null ? 'info' : ($ok ? 'good' : 'bad') }}" title="{{ $why }}">{{ $ok === null ? '•' : ($ok ? '✓' : '✗') }} {{ __('review.chk_'.$ck) }}</span>
        @endforeach
    </div>

    @foreach ($lockAll + $lockLines as $k => $msg)
        <div class="dp-lock">🔒 {{ $msg }}
            @if ($k === 'po_approved' && $poEditUrl)<a href="{{ $poEditUrl }}" target="_blank" rel="noopener">{{ __('review.open_po_edit') }} ↗</a>@endif
        </div>
    @endforeach

    @if ($state === 'changed' && $check)
        <div class="dp-warn">⚠ {{ __('review.changed_note', ['old' => $money($check->system_balance), 'new' => $money($grand)]) }}</div>
    @endif

    {{-- ═══ الورقة | السيستم ═══ --}}
    <div class="dp-sbs">
        <section class="slip" aria-label="{{ __('review.paper') }}">
            <h4>🧾 {{ __('review.paper') }}</h4>
            <label class="fl"><span>{{ __('review.serial') }} <kbd>S</kbd></span>
                <input class="dp-serial" name="paper_ref" value="{{ $doc->paper_ref }}" maxlength="30" autocomplete="off" dir="ltr"
                       placeholder="{{ __('review.serial_missing') }}" title="{{ __('review.serial_hint') }}" @unless ($canEdit) readonly @endunless>
            </label>
            @if ($dups !== [])
                <div class="dp-dup">⚠ {{ __('review.dup_with') }}
                    @foreach ($dups as $d)
                        <a href="#" data-goto="{{ ($d['type'] === 'invoice' ? 'inv-' : 'po-').$d['id'] }}" dir="ltr">{{ $d['number'] }}</a>
                    @endforeach
                </div>
            @endif
            <label class="fl"><span>{{ __('review.paper_total') }}</span>
                <input class="dp-ptotal" inputmode="decimal" dir="ltr" autocomplete="off" data-grand="{{ $grand }}">
            </label>
            <div class="dp-pdiff" hidden>{{ __('review.paper_diff') }}: <b dir="ltr"></b></div>
        </section>

        <section class="sys" aria-label="{{ __('review.system') }}">
            <h4>🖥 {{ __('review.system') }}</h4>
            <div class="sys-row">
                <span>{{ __('review.doc_date') }} <kbd>D</kbd></span>
                <span class="sys-val">
                    @if ($editable)
                        <input type="date" class="dp-date" value="{{ $date?->format('Y-m-d') }}" @if ($isInv || $doc->status === 'delivered') max="{{ today()->toDateString() }}" @endif aria-label="{{ __('review.doc_date') }}">
                    @else
                        <b dir="ltr">{{ $date?->format('Y-m-d') }}</b>
                    @endif
                </span>
            </div>
            <div class="sys-row">
                <span>{{ __('review.doc_rep') }} <kbd>R</kbd></span>
                <span class="sys-val">
                    @if ($editable)
                        <select class="dp-rep">
                            @unless ($rep)<option value="">—</option>@endunless
                            @foreach ($reps as $r)
                                <option value="{{ $r->id }}" @selected($r->id === (int) $repId)>{{ $r->displayName() }}</option>
                            @endforeach
                        </select>
                    @else
                        <b>{{ $rep?->displayName() ?? '—' }}</b>
                    @endif
                </span>
            </div>
            <div class="sys-row"><span>{{ __('review.tot_net') }}</span><b dir="ltr">{{ $money($doc->total) }}</b></div>
            <div class="sys-row"><span>{{ __('review.tot_vat') }}</span><b dir="ltr">{{ $money($doc->tax_total) }}</b></div>
            <div class="sys-row big"><span>{{ __('review.tot_incl') }}</span><b dir="ltr">{{ $money($grand) }}</b></div>
        </section>
    </div>

    {{-- ═══ البنود ═══ --}}
    <div class="dp-lines">
        <div class="tablewrap" data-noxl>
            <table>
                <thead><tr>
                    <th>{{ __('review.col_product') }}</th><th>{{ __('review.col_qty') }}</th>
                    <th>{{ __('review.col_unit') }}</th><th>{{ __('review.col_list_now') }}</th>
                    <th>{{ __('review.col_net') }}</th><th>{{ __('review.col_vat') }}</th><th>{{ __('review.col_incl') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($doc->items as $l)
                    @php
                        $now = $listPrices[$l->product_id] ?? null;
                        $off = $now !== null && abs($now - (float) $l->price) >= 0.00005;
                        $q = $isInv ? (int) $l->qty : ($l->delivered_qty !== null ? (int) $l->delivered_qty : (int) $l->qty);
                    @endphp
                    <tr class="{{ $off ? 'off' : '' }}">
                        <td>{{ $l->product?->code }} · {{ $l->product?->displayName() }}</td>
                        <td dir="ltr">{{ $q }}@if (! $isInv && $l->delivered_qty !== null && (int) $l->delivered_qty !== (int) $l->qty) <span class="rv-sub">/ {{ (int) $l->qty }}</span>@endif</td>
                        <td dir="ltr">{{ rtrim(rtrim(number_format((float) $l->price, 4, '.', ','), '0'), '.') }}</td>
                        <td dir="ltr" class="c-list" title="{{ $off ? __('review.price_off') : __('review.price_ok') }}">
                            {{ $now === null ? '—' : rtrim(rtrim(number_format($now, 4, '.', ','), '0'), '.') }} {{ $off ? '≠' : '✓' }}
                        </td>
                        <td dir="ltr">{{ $money($l->total) }}</td>
                        <td dir="ltr">{{ $money($l->tax) }}</td>
                        <td dir="ltr">{{ $money((float) $l->total + (float) $l->tax) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($linesEditable)
            <button type="button" class="btn sm dp-edit-lines">✎ {{ __('review.btn_edit_lines') }} <kbd>E</kbd></button>

            <form class="dp-lines-form" hidden>
                <div class="dp-note">ℹ️ {{ __('review.lines_stock_note') }}@if ($afterReset)<br>⚠ {{ __('review.lines_after_reset') }}@endif</div>
                <table class="dp-edit">
                    <thead><tr><th>{{ __('review.col_product') }}</th><th>{{ __('review.col_qty') }}</th><th>{{ __('review.col_unit') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($doc->items->groupBy('product_id') as $pid => $ls)
                        @php $q = $isInv ? (int) $ls->sum('qty') : (int) $ls->sum(fn ($l) => $l->delivered_qty ?? $l->qty); @endphp
                        <tr data-pid="{{ $pid }}">
                            <td>{{ $ls->first()->product?->code }} · {{ $ls->first()->product?->displayName() }}</td>
                            <td><input type="number" min="0" step="1" name="qty[{{ $pid }}]" value="{{ $q }}" dir="ltr"></td>
                            <td dir="ltr">{{ isset($listPrices[$pid]) ? rtrim(rtrim(number_format($listPrices[$pid], 4, '.', ''), '0'), '.') : '—' }}</td>
                            <td><button type="button" class="x" aria-label="✕">✕</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="dp-add">
                    <select class="dp-add-sel">
                        <option value="">{{ __('review.btn_add_product') }}</option>
                        @foreach ($products as $p)
                            <option value="{{ $p['id'] }}" data-price="{{ $p['price'] }}" data-label="{{ $p['code'] }} · {{ $p['name'] }}">{{ $p['code'] }} · {{ $p['name'] }} — {{ rtrim(rtrim(number_format($p['price'], 4, '.', ''), '0'), '.') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dp-preview" hidden></div>
                <label class="fl"><span>{{ __('review.lines_note') }}</span><input name="note" maxlength="500"></label>
                <div class="dp-actions">
                    <button type="submit" class="btn">{{ __('review.btn_save_lines') }}</button>
                    <button type="button" class="btn ghost dp-cancel">{{ __('review.btn_cancel') }} <kbd>Esc</kbd></button>
                </div>
            </form>
        @endif
    </div>

    {{-- ═══ السجل ═══ --}}
    <details class="dp-hist" @if ($events->isNotEmpty()) open @endif>
        <summary>{{ __('review.history') }} ({{ $events->count() }})</summary>
        @forelse ($events as $e)
            <div class="ev">
                <span class="ev-i">{{ $evIcon[$e->action] ?? '•' }}</span>
                <span><b>{{ __('review.ev_'.$e->action, ['number' => '']) }}</b> — {{ $e->user?->displayName() }}
                    <span class="rv-sub" dir="ltr">{{ $e->created_at?->format('Y-m-d H:i:s') }}</span>
                    @if ((float) $e->grand_delta != 0)<span class="ev-money" dir="ltr">{{ $money($e->before['grand'] ?? 0) }} → {{ $money($e->after['grand'] ?? 0) }} ({{ $e->grand_delta > 0 ? '+' : '' }}{{ $money($e->grand_delta) }})</span>@endif
                    @if ($e->action === 'serial')<span dir="ltr">{{ $e->before['paper_ref'] ?? '—' }} → {{ $e->after['paper_ref'] ?? '—' }}</span>@endif
                    @if ($e->action === 'rep'){{ $e->before['rep'] ?? '—' }} → {{ $e->after['rep'] ?? '—' }}@endif
                    @if ($e->action === 'date')<span dir="ltr">{{ $e->before['date'] ?? '—' }} → {{ $e->after['date'] ?? '—' }}</span>@endif
                    @if ($e->seconds_since_prev !== null)<span class="rv-sub">· {{ __('review.ev_seconds', ['s' => $e->seconds_since_prev]) }}</span>@endif
                    @if (! empty($e->after['review_cleared']))<span class="rv-sub">· {{ __('review.cleared_review') }}</span>@endif
                    @if ($e->note)<br><span class="rv-sub">{{ $e->note }}</span>@endif
                </span>
            </div>
        @empty
            <div class="rv-sub">{{ __('review.history_empty') }}</div>
        @endforelse
    </details>
</div>
