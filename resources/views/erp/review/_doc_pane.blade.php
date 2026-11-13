{{-- لوحة المستند الواحد في مراجعة المستندات (١١/١١) — الورقة قدام السيستم --}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $lockAll = $locks['all'];
    $lockLines = $locks['lines'];
    $editable = $canEdit && $lockAll === [];
    $linesEditable = $editable && $lockLines === [];
    $isInv = $type === 'invoice';
    $isRet = $type === 'return';
    // (١٣/١١) الأمر اللي اتسلّم: الإجمالي = المسلَّم (= قيد البيع)، مش المطلوب — «اتسلّم ٢٤ ومحسوب ٣٦»
    $storedGrand = (float) $doc->grand_total;
    $dlv = $poDelivered ?? null;
    $partialPo = $type === 'po' && $doc->status === 'delivered' && $dlv !== null && abs($dlv['grand'] - $storedGrand) >= 0.005;
    $grand = $type === 'po' && $doc->status === 'delivered' && $dlv !== null ? $dlv['grand'] : $storedGrand;
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
    $evIcon = ['serial' => '#', 'rep' => '👤', 'date' => '📅', 'proof' => '📦', 'undo' => '↶', 'ok' => '✓', 'issue' => '✗', 'reopen' => '↺', 'lines' => '✎',
        'delivered' => '📦', 'ret_price' => '💲', 'void_dup' => '🗑', 'dup_voided' => '🗑'];
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
        <div class="dp-warn">⚠ {{ __('review.changed_note', ['old' => $money($check->system_balance), 'new' => $money($storedGrand)]) }}</div>
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
                    <span class="cmp-sub" dir="ltr">{{ __('review.cmp_net_vat', ['net' => $money($dlv && $doc->status === 'delivered' ? $dlv['net'] : $doc->total), 'vat' => $money($dlv && $doc->status === 'delivered' ? $dlv['tax'] : $doc->tax_total)]) }}</span>
                    @if ($partialPo)<span class="cmp-sub dp-ordered">{{ __('review.dlv_vs_ordered', ['ordered' => $money($storedGrand)]) }}</span>@endif</td>
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
                            <small dir="ltr">{{ $l->product?->code }}</small>@if ($isRet && $l->manual_price !== null)<small class="dl-man" title="{{ $l->manual_reason }}">✎ {{ __('review.retp_manual_tag') }}</small>@endif @if ($isRet && $l->condition === 'damaged')<small class="dl-dmg">{{ __('field.return_cond_damaged') }}</small>@endif</td>
                        <td dir="ltr" class="num">{{ $q }}@if ($type === 'po' && $l->delivered_qty !== null && (int) $l->delivered_qty !== (int) $l->qty)<small>/ {{ (int) $l->qty }}</small>@endif</td>
                        <td dir="ltr" class="num c-list" title="{{ $isRet ? ($sale === null ? __('review.ret_no_sale') : ($off ? __('review.ret_price_off') : __('review.price_ok'))) : ($off ? __('review.price_off') : __('review.price_ok')) }}">
                            {{ $p4($l->price) }}
                            @if ($isRet)
                                <small>@if ($sale !== null){{ __('review.dl_sale') }} {{ $p4($sale) }} {{ $off ? '≠' : '✓' }}@if ($l->invoiceItem?->invoice) · <a href="#" data-goto="inv-{{ $l->invoiceItem->invoice_id }}">{{ $l->invoiceItem->invoice->number }}</a>@endif @else{{ __('review.dl_list') }} {{ $now === null ? '—' : $p4($now) }}@endif</small>
                            @else
                                <small>{{ __('review.dl_list') }} {{ $now === null ? '—' : $p4($now) }} {{ $now === null ? '' : ($off ? '≠' : '✓') }}</small>
                            @endif
                        </td>
                        @php
                            $lt = ['net' => (float) $l->total, 'tax' => (float) $l->tax];
                            if ($type === 'po' && $doc->status === 'delivered' && $l->delivered_qty !== null && (int) $l->delivered_qty !== (int) $l->qty) {
                                $lt = \App\Services\Tax::line((float) $l->price, (int) $l->delivered_qty, (float) ($l->tax_rate ?? 0));
                            }
                        @endphp
                        <td dir="ltr" class="num"><b>{{ $money($lt['net'] + $lt['tax']) }}</b><small>{{ $money($lt['net']) }} + {{ $money($lt['tax']) }}</small></td>
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


    {{-- ═══ (١٣/١١) أدوات حل الملاحظات: المسلَّم · سعر بند المرتجع · مكرر · إلغاء أمر ماتسلّمش ═══ --}}
    @if ($type === 'po' && $doc->status === 'delivered' && $editable)
        <button type="button" class="btn sm dp-tool-btn" data-tool="dlv">📦 {{ __('review.dlv_btn') }}</button>
        <form class="dp-tool dp-dlv-form" data-tool="dlv" hidden>
            <div class="dp-note">ℹ️ {{ __('review.dlv_note') }}</div>
            <table class="dp-edit">
                <thead><tr><th>{{ __('review.col_product') }}</th><th>{{ __('review.dlv_ordered') }}</th><th>{{ __('review.dlv_delivered') }}</th></tr></thead>
                <tbody>
                @foreach ($doc->items as $l)
                    <tr>
                        <td>{{ $l->product?->code }} · {{ $l->product?->displayName() }}</td>
                        <td dir="ltr" class="num">{{ (int) $l->qty }}</td>
                        <td><input type="number" min="0" max="{{ (int) $l->qty }}" step="1" name="dq[{{ $l->id }}]" value="{{ $l->delivered_qty ?? (int) $l->qty }}" dir="ltr" aria-label="{{ __('review.dlv_delivered') }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <label class="fl"><span>{{ __('review.lines_note') }}</span><input name="note" maxlength="500"></label>
            <div class="dp-actions">
                <button type="submit" class="btn">{{ __('review.dlv_save') }}</button>
                <button type="button" class="btn ghost dp-tool-close">{{ __('review.btn_cancel') }}</button>
            </div>
        </form>
    @endif

    @if ($isRet && $editable)
        <button type="button" class="btn sm dp-tool-btn" data-tool="retp">💲 {{ __('review.retp_btn') }}</button>
        <form class="dp-tool dp-retp-form" data-tool="retp" hidden>
            <div class="dp-note">ℹ️ {{ __('review.retp_note') }}</div>
            <label class="fl"><span>{{ __('review.retp_line') }}</span>
                <select name="item_id" class="dp-retp-line" required>
                    @foreach ($doc->items as $l)
                        <option value="{{ $l->id }}" data-pid="{{ $l->product_id }}">{{ $l->product?->displayName() }} — {{ (int) $l->qty }} × {{ $p4($l->price) }}@if ($l->manual_price !== null) ✎@elseif ($l->invoice_item_id) · {{ $l->invoiceItem?->invoice?->number }}@endif</option>
                    @endforeach
                </select></label>
            <div class="pf-row dp-retp-modes">
                <label><input type="radio" name="mode" value="invoice" checked> {{ __('review.retp_mode_invoice') }}</label>
                <label><input type="radio" name="mode" value="manual"> {{ __('review.retp_mode_manual') }}</label>
                <label><input type="radio" name="mode" value="list"> {{ __('review.retp_mode_list') }}</label>
            </div>
            <label class="fl dp-retp-inv"><span>{{ __('review.retp_invoice') }}</span>
                <select name="invoice_item_id">
                    <option value="">—</option>
                    @foreach ($retSources as $pid => $srcs)
                        @foreach ($srcs as $s)
                            <option value="{{ $s['id'] }}" data-pid="{{ $pid }}">{{ $s['number'] }} · {{ $s['date'] }} — {{ $s['qty'] }} × {{ $p4($s['price']) }} ({{ __('review.retp_left', ['n' => $s['left']]) }})</option>
                        @endforeach
                    @endforeach
                </select>
                <small class="rv-sub dp-retp-none" hidden>{{ __('review.retp_no_invoice') }}</small></label>
            <div class="dp-retp-man" hidden>
                <label class="fl"><span>{{ __('review.retp_price') }}</span><input name="price" inputmode="decimal" dir="ltr" autocomplete="off"></label>
                <label class="fl"><span>{{ __('review.retp_reason_l') }}</span><input name="reason" maxlength="190"></label>
            </div>
            <div class="dp-actions">
                <button type="submit" class="btn">{{ __('review.retp_save') }}</button>
                <button type="button" class="btn ghost dp-tool-close">{{ __('review.btn_cancel') }}</button>
            </div>
        </form>
    @endif

    @if ($canVoid && $lockAll === [])
        <button type="button" class="btn sm bad dp-tool-btn" data-tool="void">🗑 {{ __('review.dup_btn') }}</button>
        <form class="dp-tool dp-void-form" data-tool="void" hidden>
            <div class="dp-warn">⚠ {{ __('review.dup_note', ['number' => $doc->number]) }}@if ($afterReset)<br>{{ __('review.dup_custody') }}@endif</div>
            <label class="fl"><span>{{ __('review.dup_orig') }}</span>
                <input name="original" required maxlength="30" dir="ltr" autocomplete="off" placeholder="{{ $isInv ? 'INV-' : 'RET-' }}…"
                       value="{{ collect($dups)->firstWhere('type', $type)['number'] ?? '' }}"></label>
            <label class="fl"><span>{{ __('review.lines_note') }}</span><input name="note" maxlength="500"></label>
            <div class="dp-actions">
                <button type="submit" class="btn bad">{{ __('review.dup_save') }}</button>
                <button type="button" class="btn ghost dp-tool-close">{{ __('review.btn_cancel') }}</button>
            </div>
        </form>
    @endif

    @if ($type === 'po' && in_array($doc->status, ['pending', 'arrived'], true))
        @if ($canCancelPo)
            <button type="button" class="btn sm bad dp-tool-btn" data-tool="pocancel">⊘ {{ __('review.poc_btn') }}</button>
            {{-- نفس مسار إلغاء الأمر في العمليات — مفيش قيد على العميل يترد (الأمر ماتسلّمش) --}}
            <form class="dp-tool" data-tool="pocancel" method="POST" action="{{ route('ops.pos.cancel', $doc) }}" hidden>
                @csrf
                <div class="dp-note">ℹ️ {{ __('review.poc_note') }}</div>
                <label class="fl"><span>{{ __('review.poc_reason') }}</span><input name="reason" required minlength="3" maxlength="300"></label>
                @if ($doc->status === 'arrived' || $doc->pickOrder?->status === 'handed')
                    <label class="fl"><span>{{ __('review.poc_goods') }}</span>
                        <select name="mode"><option value="custody">{{ __('review.poc_mode_custody') }}</option><option value="warehouse">{{ __('review.poc_mode_wh') }}</option></select></label>
                @else
                    <input type="hidden" name="mode" value="custody">
                @endif
                <div class="dp-actions">
                    <button type="submit" class="btn bad">{{ __('review.poc_save') }}</button>
                    <button type="button" class="btn ghost dp-tool-close">{{ __('review.btn_cancel') }}</button>
                </div>
            </form>
        @else
            <div class="dp-note">ℹ️ {{ __('review.poc_ask_admin') }}</div>
        @endif
    @endif

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
                    case 'delivered': return e(__('review.evl_delivered')).' — '.$from($money($b['grand'] ?? 0), $money($a['grand'] ?? 0));
                    case 'ret_price': return e(__('review.evl_ret_price', ['product' => $a['product'] ?? '', 'mode' => __('review.retp_mode_'.($a['mode'] ?? 'list'))])).' — '.$from($money($b['grand'] ?? 0), $money($a['grand'] ?? 0));
                    case 'dup_voided': return e(__('review.evl_dup_voided', ['number' => $a['duplicate'] ?? '']));
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
