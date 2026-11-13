{{-- لوحة المستند الواحد في مراجعة المستندات (١١/١١) — الورقة قدام السيستم --}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $lockAll = $locks['all'];
    $lockLines = $locks['lines'];
    $editable = $canEdit && $lockAll === [];
    $linesEditable = $editable && $lockLines === [];
    $isInv = $type === 'invoice';
    $isRet = $type === 'return';
    $grand = (float) $doc->grand_total;
    $u = auth()->user();
    $docUrl = match ($type) {
        'invoice' => \App\Support\Access::allows($u, 'ops.invoice') ? route('ops.invoice', $doc) : null,
        'return' => \App\Support\Access::allows($u, 'ops.returns.show') ? route('ops.returns.show', $doc) : null,
        default => \App\Support\Access::allows($u, 'ops.pos.show') ? route('ops.pos.show', $doc) : null,
    };
    $invUrl = \App\Support\Access::allows($u, 'ops.invoice');
    $poEditUrl = $type === 'po' && isset($lockLines['po_approved']) && \App\Support\Access::allows($u, 'ops.po.edit') ? route('ops.po.edit', $doc) : null;
    $chain = $client?->group;
    $mother = $chain?->motherCompany;
    $evIcon = ['serial' => '#', 'rep' => '👤', 'date' => '📅', 'proof' => '📦', 'undo' => '↶', 'ok' => '✓', 'issue' => '✗', 'reopen' => '↺', 'lines' => '✎'];
    $yn = fn ($v) => $v ? '✓' : '✗';
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
            · @if ($isRet){{ __('field.return_policy_'.$doc->policy) }}@if ($doc->damaged_units) · {{ __('review.ret_damaged_n', ['n' => $doc->damaged_units]) }}@endif
              @else{{ $isInv ? __('review.pay_'.($doc->payment === 'cash' ? 'cash' : 'credit')) : __('review.po_'.(in_array($doc->status, ['pending', 'arrived', 'delivered'], true) ? $doc->status : 'pending')) }}@endif
        </span>
    </div>

    {{-- ═══ العلامة — فوق وثابتة: ✓ مايحتاجش سكرول ═══ --}}
    <div class="dp-bar">
        @if ($state !== 'ok')
            <button type="button" class="btn ok dp-ok">{{ __('review.btn_ok') }} <kbd>Enter</kbd></button>
            <button type="button" class="btn bad dp-issue">{{ __('review.btn_issue') }} <kbd>X</kbd></button>
        @elseif ($canReopen && ! $canUndo)
            <button type="button" class="btn dp-reopen">↺ {{ __('review.btn_reopen') }}</button>
        @endif
        @if ($canUndo)
            {{-- (١٣/١١) دوسة غلط؟ المراجِع بيرجّع علامته هو في نفس اليوم --}}
            <button type="button" class="btn dp-undo" title="{{ __('review.undo_hint') }}">↶ {{ __('review.btn_undo') }} <kbd>U</kbd></button>
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
            <span class="{{ $ok === null ? 'info' : ($ok ? 'good' : 'bad') }}" data-chk="{{ $ck }}" @if ($ck === 'serial' || ($ck === 'proof' && $proofHard)) data-hard="1" @endif title="{{ $why }}">{{ $ok === null ? '•' : ($ok ? '✓' : '✗') }} {{ __('review.chk_'.$ck) }}</span>
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

    {{-- ═══ الورقة | السيستم — نفس الصفوف بنفس الترتيب للتلات أنواع (١٣/١١):
         سيريال ← الفاتورة الأصلية (مرتجع) ← الإثبات ← التاريخ ← المندوب ← الإجمالي ═══ --}}
    @php
        $needProof = \App\Services\Review\DocReview::needsProof($doc);
        $hasProof = \App\Services\Review\DocReview::hasProof($doc);
        $p4 = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    @endphp
    <table class="cmp" data-plain>
        <colgroup><col class="cmp-c-th"><col><col></colgroup>
        <thead><tr>
            <th></th>
            <th class="cmp-paper">🧾 {{ __('review.paper') }}</th>
            <th class="cmp-sys">🖥 {{ __('review.system') }}</th>
        </tr></thead>
        <tbody>
            <tr>
                <th scope="row">{{ $isRet ? __('review.ret_serial') : __('review.col_serial') }} <kbd>S</kbd></th>
                <td class="cmp-paper">
                    <input class="dp-serial" name="paper_ref" value="{{ $doc->paper_ref }}" maxlength="30" autocomplete="off" dir="ltr" inputmode="numeric"
                           placeholder="{{ __('review.serial_missing') }}" title="{{ __('review.serial_hint') }}" aria-label="{{ __('review.serial') }}" @unless ($canEdit) readonly @endunless>
                </td>
                <td class="cmp-sys">
                    @if ($dups !== [])
                        <span class="dp-dup">⚠ {{ __('review.dup_with') }}
                            @foreach ($dups as $d)<a href="#" data-goto="{{ \App\Services\Review\DocReview::keyFor($d['type'], $d['id']) }}" dir="ltr">{{ $d['number'] }}</a>@endforeach
                        </span>
                    @elseif (filled($doc->paper_ref))
                        <span class="cmp-ok">✓ {{ __('review.serial_unique') }}</span>
                    @else
                        <span class="cmp-na">—</span>
                    @endif
                </td>
            </tr>
            @if ($isRet)
                <tr>
                    <th scope="row">{{ __('review.ret_orig') }}</th>
                    <td class="cmp-wide" colspan="2">
                        @forelse ($retLinks as $inv)
                            <a href="#" data-goto="inv-{{ $inv->id }}" dir="ltr" title="{{ __('review.ret_orig_open') }}">{{ $inv->number }}</a>@if ($invUrl)<a href="{{ route('ops.invoice', $inv) }}" target="_blank" rel="noopener" class="rv-sub"> ↗</a>@endif @if (! $loop->last)· @endif
                        @empty
                            <span class="cmp-na">{{ __('review.ret_orig_none') }}</span>
                        @endforelse
                    </td>
                </tr>
            @endif
            {{-- الإثبات: مفاتيح في سطر واحد، وحالته في الآخر — بيتحفظ أول ما يتغير --}}
            <tr class="cmp-proof {{ $needProof && ! $hasProof ? 'bad' : ($needProof ? 'good' : '') }}">
                <th scope="row">{{ __('review.chk_proof') }} @unless ($isRet)<kbd>1</kbd>@endunless<kbd>2</kbd></th>
                <td class="cmp-wide" colspan="2">
                    <div class="pf-row">
                        @unless ($isRet)
                            <label class="sw"><input type="checkbox" role="switch" class="dp-receipt" @checked($doc->receipt_note) @disabled(! $canProof)><i aria-hidden="true"></i>{{ __('review.pf_receipt_short') }}</label>
                            <input class="dp-receipt-no" value="{{ $doc->receipt_note_no }}" maxlength="40" dir="ltr" autocomplete="off"
                                   placeholder="{{ __('review.pf_no_short') }}" title="{{ __('review.proof_no_ph') }}" aria-label="{{ __('review.proof_receipt_no') }}" @unless ($canProof) readonly @endunless>
                        @endunless
                        <label class="sw"><input type="checkbox" role="switch" class="dp-signed" @checked($doc->paper_signed) @disabled(! $canProof)><i aria-hidden="true"></i>{{ $isRet ? __('review.ret_signed') : __('review.proof_signed') }}</label>
                        <span class="pf-st">
                            @if (! $needProof)<span class="cmp-na">{{ __('review.proof_not_delivered') }}</span>
                            @elseif ($hasProof)<span class="cmp-ok">✓ {{ __('review.proof_has') }}</span>
                            @else<span class="cmp-bad">✗ {{ $isRet ? __('review.ret_proof_none') : __('review.proof_none') }}</span>@endif
                        </span>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">{{ __('review.doc_date') }} <kbd>D</kbd></th>
                <td class="cmp-paper cmp-na">{{ __('review.cmp_look') }}</td>
                <td class="cmp-sys">
                    @if ($editable)
                        <input type="date" class="dp-date" value="{{ $date?->format('Y-m-d') }}" @if ($type !== 'po' || $doc->status === 'delivered') max="{{ today()->toDateString() }}" @endif aria-label="{{ __('review.doc_date') }}">
                    @else
                        <b dir="ltr">{{ $date?->format('Y-m-d') }}</b>
                    @endif
                </td>
            </tr>
            <tr>
                <th scope="row">{{ __('review.doc_rep') }} <kbd>R</kbd></th>
                <td class="cmp-paper cmp-na">{{ __('review.cmp_look') }}</td>
                <td class="cmp-sys">
                    @if ($editable)
                        <select class="dp-rep" aria-label="{{ __('review.doc_rep') }}">
                            @unless ($rep)<option value="">—</option>@endunless
                            @foreach ($reps as $r)
                                <option value="{{ $r->id }}" @selected($r->id === (int) $repId)>{{ $r->displayName() }}</option>
                            @endforeach
                        </select>
                    @else
                        <b>{{ $rep?->displayName() ?? '—' }}</b>
                    @endif
                </td>
            </tr>
            <tr class="cmp-total">
                <th scope="row">{{ $isRet ? __('review.ret_credit') : __('review.tot_incl') }} <kbd>P</kbd></th>
                <td class="cmp-paper">
                    <input class="dp-ptotal" inputmode="decimal" dir="ltr" autocomplete="off" data-grand="{{ $grand }}" placeholder="{{ __('review.paper_total_ph') }}" aria-label="{{ __('review.paper_total') }}">
                </td>
                <td class="cmp-sys"><b dir="ltr" class="cmp-grand">{{ $money($grand) }}</b>
                    <span class="cmp-sub" dir="ltr">{{ __('review.cmp_net_vat', ['net' => $money($doc->total), 'vat' => $money($doc->tax_total)]) }}</span></td>
            </tr>
            <tr class="dp-pdiff" hidden>
                <th scope="row">{{ __('review.paper_diff') }}</th>
                <td colspan="2"><b dir="ltr"></b> <span class="pd-txt" data-ok="{{ __('review.pdiff_ok') }}" data-bad="{{ __('review.pdiff_bad') }}"></span></td>
            </tr>
        </tbody>
    </table>

    {{-- ═══ البنود — ٤ أعمدة تكفي اللوحة من غير سكرول: السعر تحته سعر البيع/القايمة، والإجمالي تحته الصافي والضريبة ═══ --}}
    <div class="dp-lines">
        <div class="tablewrap" data-noxl>
            <table class="dl-t">
                <colgroup><col><col class="dl-c-q"><col class="dl-c-p"><col class="dl-c-t"></colgroup>
                <thead><tr>
                    <th>{{ __('review.col_product') }}</th><th>{{ __('review.col_qty') }}</th>
                    <th>{{ __('review.col_unit') }}</th><th>{{ __('review.col_incl') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($doc->items as $l)
                    @php
                        $now = $listPrices[$l->product_id] ?? null;
                        // المرتجع بيتقارن بسعر البيع اللي بيعكسه — ولو مالوش فاتورة بالقايمة
                        $sale = $isRet ? ($salePrices[$l->id] ?? null) : null;
                        $ref = $sale ?? $now;
                        $off = $ref !== null && ($isRet ? abs($ref - (float) $l->price) >= 0.005 : abs($ref - (float) $l->price) >= 0.00005);
                        $q = $type !== 'po' ? (int) $l->qty : ($l->delivered_qty !== null ? (int) $l->delivered_qty : (int) $l->qty);
                    @endphp
                    <tr class="{{ $off ? 'off' : '' }}">
                        <td class="dl-name">{{ $l->product?->displayName() }}
                            <small dir="ltr">{{ $l->product?->code }}</small>@if ($isRet && $l->condition === 'damaged')<small class="dl-dmg">{{ __('field.return_cond_damaged') }}</small>@endif</td>
                        <td dir="ltr" class="num">{{ $q }}@if ($type === 'po' && $l->delivered_qty !== null && (int) $l->delivered_qty !== (int) $l->qty)<small>/ {{ (int) $l->qty }}</small>@endif</td>
                        <td dir="ltr" class="num c-list" title="{{ $isRet ? ($sale === null ? __('review.ret_no_sale') : ($off ? __('review.ret_price_off') : __('review.price_ok'))) : ($off ? __('review.price_off') : __('review.price_ok')) }}">
                            {{ $p4($l->price) }}
                            @if ($isRet)
                                <small>@if ($sale !== null){{ __('review.dl_sale') }} {{ $p4($sale) }} {{ $off ? '≠' : '✓' }}@if ($l->invoiceItem?->invoice) · <a href="#" data-goto="inv-{{ $l->invoiceItem->invoice_id }}">{{ $l->invoiceItem->invoice->number }}</a>@endif @else{{ __('review.dl_list') }} {{ $now === null ? '—' : $p4($now) }}@endif</small>
                            @else
                                <small>{{ __('review.dl_list') }} {{ $now === null ? '—' : $p4($now) }} {{ $now === null ? '' : ($off ? '≠' : '✓') }}</small>
                            @endif
                        </td>
                        <td dir="ltr" class="num"><b>{{ $money((float) $l->total + (float) $l->tax) }}</b><small>{{ $money($l->total) }} + {{ $money($l->tax) }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($linesEditable)
            <button type="button" class="btn sm dp-edit-lines">✎ {{ __('review.btn_edit_lines') }} <kbd>E</kbd></button>

            <form class="dp-lines-form" hidden>
                <div class="dp-note">ℹ️ {{ $isRet ? __('review.ret_lines_note') : __('review.lines_stock_note') }}@if ($afterReset)<br>⚠ {{ __('review.lines_after_reset') }}@endif</div>
                <table class="dp-edit">
                    <thead><tr><th>{{ __('review.col_product') }}</th><th>{{ __('review.col_qty') }}</th><th>{{ __('review.col_unit') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($doc->items->groupBy('product_id') as $pid => $ls)
                        @php $q = $type !== 'po' ? (int) $ls->sum('qty') : (int) $ls->sum(fn ($l) => $l->delivered_qty ?? $l->qty); @endphp
                        <tr data-pid="{{ $pid }}">
                            <td>{{ $ls->first()->product?->code }} · {{ $ls->first()->product?->displayName() }}</td>
                            <td><input type="number" min="0" step="1" name="qty[{{ $pid }}]" value="{{ $q }}" dir="ltr"></td>
                            <td dir="ltr">{{ $isRet ? rtrim(rtrim(number_format((float) $ls->first()->price, 4, '.', ''), '0'), '.') : (isset($listPrices[$pid]) ? rtrim(rtrim(number_format($listPrices[$pid], 4, '.', ''), '0'), '.') : '—') }}</td>
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
                    <button type="submit" class="btn">{{ $isRet ? __('review.ret_btn_save') : __('review.btn_save_lines') }}</button>
                    <button type="button" class="btn ghost dp-cancel">{{ __('review.btn_cancel') }} <kbd>Esc</kbd></button>
                </div>
            </form>
        @endif
    </div>

    {{-- ═══ السجل ═══ --}}
    <details class="dp-hist" @if ($events->isNotEmpty()) open @endif>
        <summary>{{ __('review.history') }} ({{ $events->count() }})</summary>
        @php
            // (١٣/١١) سطر مقروء لكل حركة: «جاد علّم "إمضاء / ختم" ✓ — 21:21» — الأحدث فوق
            $q2 = fn ($v) => '«'.e((string) $v).'»';
            $evText = function ($e) use ($money, $q2) {
                $b = $e->before ?? [];
                $a = $e->after ?? [];
                $from = fn ($o, $n) => __('review.evl_from_to', ['old' => $o === null || $o === '' ? '—' : e((string) $o), 'new' => $n === null || $n === '' ? '—' : e((string) $n)]);
                switch ($e->action) {
                    case 'ok': return e(__('review.evl_ok'));
                    case 'issue': return e(__('review.evl_issue'));
                    case 'reopen': return e(__('review.evl_reopen'));
                    case 'undo': return e(__('review.evl_undo'));
                    case 'serial': return empty($b['paper_ref'])
                        ? e(__('review.evl_serial_set')).' <b dir="ltr">'.e((string) ($a['paper_ref'] ?? '—')).'</b>'
                        : e(__('review.evl_serial')).' '.$from($b['paper_ref'] ?? null, $a['paper_ref'] ?? null);
                    case 'date': return e(__('review.evl_date')).' '.$from($b['date'] ?? null, $a['date'] ?? null);
                    case 'rep': return e(__('review.evl_rep')).' '.$from($b['rep'] ?? null, $a['rep'] ?? null);
                    case 'lines': return e(__('review.evl_lines')).' — '.$from($money($b['grand'] ?? 0), $money($a['grand'] ?? 0))
                        .' <b dir="ltr" class="'.((float) $e->grand_delta > 0 ? 'd-up' : 'd-down').'">('.((float) $e->grand_delta > 0 ? '+' : '').$money($e->grand_delta).')</b>';
                    case 'proof':
                        $parts = [];
                        foreach (['receipt_note' => __('review.proof_receipt'), 'paper_signed' => __('review.proof_signed')] as $k => $label) {
                            if (array_key_exists($k, $a) && (bool) ($b[$k] ?? false) !== (bool) $a[$k]) {
                                $parts[] = e($a[$k] ? __('review.evl_tick') : __('review.evl_untick')).' '.$q2($label).($a[$k] ? ' ✓' : '');
                            }
                        }
                        if (($a['receipt_note_no'] ?? null) !== ($b['receipt_note_no'] ?? null) && ! empty($a['receipt_note_no'])) {
                            $parts[] = e(__('review.evl_receipt_no')).' <b dir="ltr">'.e($a['receipt_note_no']).'</b>';
                        }

                        return implode('، ', $parts ?: [e(__('review.ev_proof', ['number' => '']))]);
                    default: return e(__('review.ev_'.$e->action, ['number' => '']));
                }
            };
        @endphp
        @forelse ($events as $e)
            <div class="ev ev-{{ $e->action }}">
                <span class="ev-i" aria-hidden="true">{{ $evIcon[$e->action] ?? '•' }}</span>
                <span class="ev-t"><b>{{ $e->user?->displayName() }}</b> {!! $evText($e) !!}
                    <span class="ev-at" dir="ltr" title="{{ $e->created_at?->format('Y-m-d H:i:s') }}">— {{ $e->created_at?->isToday() ? $e->created_at->format('H:i') : $e->created_at?->format('m-d H:i') }}</span>
                    @if (! empty($e->after['review_cleared']))<span class="rv-sub">· {{ __('review.cleared_review') }}</span>@endif
                    @if ($e->note)<span class="ev-note">{{ $e->note }}</span>@endif
                </span>
            </div>
        @empty
            <div class="rv-sub">{{ __('review.history_empty') }}</div>
        @endforelse
    </details>
</div>
