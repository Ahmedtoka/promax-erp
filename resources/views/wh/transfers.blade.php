@extends('layouts.system')

@section('title', __('stock.transfers'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->isManager();

    // ⚠️ قايمة الأصناف بتتبني هنا مرة واحدة كـ HTML وبتتحط في قالب البند.
    // ممنوع نلف على $products جوه الجافاسكريبت — البليد مابيشتغلش هناك.
    $productOptions = '<option value="">'.e(__('stock.choose_item')).'</option>';
    foreach ($products as $p) {
        $productOptions .= '<option value="'.(int) $p->id.'">'
            .e($p->code.' — '.$p->displayName())
            .'</option>';
    }

    // ⚠️ **الباتشات الحقيقية بتغذّي قايمة الباتش.** قبل كده كان
    // رقم الباتش وتاريخ الإنتاج نص حر — يعني نفس الكرتونة بتاخد رقم
    // في العاشر ورقم تاني في المعادي فترتيب الصلاحية بيتكسر، والأهم
    // إن مافيش أي ضمان إن الكمية دي موجودة عشان تتبعت أصلاً.
    //
    // ⚠️ **`json_encode` في `@php` مش `@json` في الـHTML.** التوجيه
    // بمصفوفة بيكسّر بارسر البليد؛ والفلاجز ضرورية لأن الناتج بيقع
    // جوه سمة HTML.
    $batchData = json_encode($batches->map(fn ($b) => [
        'id' => $b->id,
        'w' => (int) $b->warehouse_id,
        'p' => (int) $b->product_id,
        'no' => $b->batch_no,
        'prod' => $b->produced_on?->toDateString(),
        'exp' => $b->expires_on?->toDateString(),
        'left' => (int) $b->qty_remaining,
    ])->values(), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index') }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager)
        <button class="btn gold" onclick="openDlg('dlgNewTr')">+ {{ __('stock.new_transfer') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🚚 {{ __('stock.transfers') }}
        <span class="side">{{ __('stock.transfer_hint') }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.transfer') }}</th>
                <th>{{ __('stock.from_warehouse') }}</th>
                <th>{{ __('stock.to_warehouse') }}</th>
                <th>{{ __('stock.sent_on') }}</th>
                <th class="num">{{ __('stock.qty_sent') }}</th>
                <th class="num">{{ __('stock.qty_received') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>

            @forelse ($transfers as $t)
                <tr>
                    <td class="num">
                        <a href="{{ route('wh.transfers.show', $t) }}"><b>{{ $t->number }}</b></a>
                        <br><span style="font-size:10.5px;color:var(--muted)">
                            {{ __('stock.sent_by') }}: {{ $t->sender?->name ?? '—' }}
                        </span>
                    </td>
                    <td>{{ $t->fromWarehouse?->displayName() ?? '—' }}</td>
                    <td>{{ $t->toWarehouse?->displayName() ?? '—' }}</td>
                    <td>{{ $t->sent_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $fmt($t->qtySent()) }}</td>
                    <td class="num">
                        @if ($t->status === 'received')
                            {{ $fmt($t->qtyReceived()) }}
                            @if ($t->hasVariance())
                                <br><span class="badge b-red">{{ __('stock.variance') }}
                                    {{ $fmt($t->qtyReceived() - $t->qtySent()) }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td><span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span></td>
                    <td class="num" style="white-space:nowrap">
                        {{-- ⚠️ **الطباعة متاحة دايماً.** الورقة الممضية ممكن
                             تضيع، واللي محتاج نسخة تانية مش هيعرف يطلّعها
                             من غير اللينك ده. --}}
                        <a class="btn sm" href="{{ route('wh.transfers.print', $t) }}">🖨️</a>
                        @if ($t->status === 'received')
                            <a class="btn sm" href="{{ route('wh.transfers.receipt_print', $t) }}">
                                {{ __('stock.receipt_note') }}
                            </a>
                        @else
                            <a class="btn sm gold" href="{{ route('wh.transfers.show', $t) }}">
                                {{ __('stock.receive_transfer') }} ←
                            </a>
                        @endif
                    </td>
                </tr>

                {{-- بنود التحويل تحت كل صف — المستلم يشوف اللي جايله من غير ما يفتح صفحة --}}
                <tr>
                    <td colspan="8" style="padding:6px 14px 12px">
                        @foreach ($t->items as $it)
                            <span class="badge b-gray" style="margin:2px 2px 0 0;display:inline-block">
                                {{ $it->product?->displayName() ?? '—' }}
                                · {{ $it->batch_no }}
                                · {{ $fmt($it->qty_sent) }}
                                @if ($it->expires_on)
                                    · {{ $it->expires_on->format('Y-m-d') }}
                                @endif
                            </span>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_transfers') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    <div class="pag">{{ $transfers->links('pagination::simple-default') }}</div>
</div>

@if ($manager)
{{-- ═══════════════ تحويل جديد ═══════════════ --}}
<dialog id="dlgNewTr">
    <form class="dlg" method="POST" action="{{ route('wh.transfers.store') }}" style="width:min(1040px,96vw)">
        @csrf
        <h4>🚚 {{ __('stock.new_transfer') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.from_warehouse') }}</label>
                <select name="from_warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }} — {{ $w->typeLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.to_warehouse') }}</label>
                <select name="to_warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }} — {{ $w->typeLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.sent_on') }}</label>
                <input type="date" name="sent_on" value="{{ today()->toDateString() }}" required style="width:100%">
            </div>
            <div>
                {{-- ⚠️ اللي بيشيل مش دايماً يوزر في السيستم (عربية
                     مؤجّرة، سواق من بره)، فاسم نصي مش قايمة. --}}
                <label class="f">{{ __('stock.carrier') }}</label>
                <input type="text" name="carrier_name" maxlength="120" style="width:100%"
                       placeholder="{{ __('stock.carrier_ph') }}">
            </div>
        </div>

        <div class="alert info" style="margin-bottom:10px">{{ __('stock.transfer_hint') }}</div>

        <div class="tablewrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('stock.item') }}</th>
                        <th>{{ __('stock.batch_no') }}</th>
                        <th>{{ __('stock.produced_on') }}</th>
                        <th class="num">{{ __('stock.available') }}</th>
                        <th class="num">{{ __('stock.qty') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="trRows"></tbody>
            </table>
        </div>

        <div style="margin-top:10px">
            <button class="btn" type="button" onclick="trAddLine()">+ {{ __('stock.add_line') }}</button>
        </div>

        <div style="margin:12px 0">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%"></textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewTr')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- قالب البند — الأوبشنز مبنية مرة واحدة في PHP فوق --}}
<template id="trTpl">
    <tr>
        <td>
            <select name="lines[IDX][product_id]" required style="width:100%"
                    data-role="prod" onchange="trFillBatches(this)">{!! $productOptions !!}</select>
        </td>
        {{-- ⚠️ **قايمة مش خانة كتابة.** الباتش لازم يكون موجود فعلاً في
             المخزن المرسل — ده اللي بيخلّي التحويل يخصم بضاعة حقيقية
             بدل ما يخلق كمية من العدم. --}}
        <td>
            <select name="lines[IDX][source_batch_id]" required style="width:150px"
                    data-role="batch" onchange="trShowBatch(this)"></select>
        </td>
        {{-- تاريخ الإنتاج بيجي من الباتش — مش بيتكتب. المستلم هو اللي
             يقدر يصححه وقت الاستلام لو الورقة على الكرتونة مختلفة. --}}
        <td class="num" data-role="prodOn" style="color:var(--muted);font-size:11.5px">—</td>
        <td class="num" data-role="left" style="color:var(--muted);font-size:11.5px">—</td>
        <td class="num"><input type="number" min="1" name="lines[IDX][qty]" required style="width:90px"
                               data-role="qty"></td>
        <td class="num"><button class="btn sm" type="button" onclick="this.closest('tr').remove()">×</button></td>
    </tr>
</template>

@endif

@endsection

@section('scripts')
<script>
    let trIdx = 0;

    /**
     * كل الباتشات القابلة للبيع في كل المخازن.
     * ⚠️ الفلترة بتتعمل هنا مش بكويري لكل سطر — الفورم بيتملّى
     * وانت بتكتب، وطلب سيرفر لكل تغيير في قايمة الصنف بيخلّي
     * الشاشة تتلكّك على اتصال ضعيف في المخزن.
     */
    const TR_BATCHES = @if (isset($batchData)) {!! $batchData !!} @else [] @endif;

    /** المخزن اللي بنبعت منه دلوقتي */
    function trFromWarehouse() {
        const el = document.querySelector('[name="from_warehouse_id"]');

        return el ? Number(el.value || 0) : 0;
    }

    /** بيملا قايمة الباتشات المتاحة للصنف ده في المخزن المرسل */
    function trFillBatches(sel) {
        const row = sel.closest('tr');
        const batchSel = row.querySelector('[data-role="batch"]');
        const productId = Number(sel.value || 0);
        const warehouseId = trFromWarehouse();

        batchSel.innerHTML = '';
        row.querySelector('[data-role="prodOn"]').textContent = '—';
        row.querySelector('[data-role="left"]').textContent = '—';

        const rows = TR_BATCHES.filter(b => b.p === productId && b.w === warehouseId);

        if (rows.length === 0) {
            // ⚠️ الرسالة دي مهمة: القايمة الفاضية من غير سبب بتخلّي
            // اللي قدامها يفتكر إن الشاشة بايظة، والسبب الحقيقي إن
            // المخزن مافيهوش رصيد من الصنف ده أصلاً.
            batchSel.innerHTML = '<option value="">'
                + @json(__('stock.no_batches_here')) + '</option>';

            return;
        }

        rows.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.no + ' · ' + (b.exp || '—') + ' · ' + b.left.toLocaleString();
            batchSel.appendChild(opt);
        });

        trShowBatch(batchSel);
    }

    /** بيعرض تاريخ الإنتاج والمتاح للباتش المختار ويحدّ الكمية بيه */
    function trShowBatch(sel) {
        const row = sel.closest('tr');
        const batch = TR_BATCHES.find(b => String(b.id) === String(sel.value));
        const qty = row.querySelector('[data-role="qty"]');

        row.querySelector('[data-role="prodOn"]').textContent = batch ? (batch.prod || '—') : '—';
        row.querySelector('[data-role="left"]').textContent = batch ? batch.left.toLocaleString() : '—';

        // ⚠️ السيرفر بيرفض الزيادة برضه، بس الشاشة بتقولها قبل ما
        // اللي بيجهّز الشحنة يخلّص كل السطور ويترمي عليه خطأ.
        if (qty) {
            qty.max = batch ? batch.left : '';
        }
    }

    /** تغيير المخزن المرسل بيغيّر كل قوايم الباتشات */
    function trReloadAll() {
        document.querySelectorAll('#trRows [data-role="prod"]').forEach(trFillBatches);
    }

    function trAddLine() {
        const tpl = document.getElementById('trTpl');
        if (! tpl) { return; }   // غير المدير مايشوفش فورم التحويل خالص
        const row = tpl.content.cloneNode(true);
        row.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('IDX', trIdx);
        });
        document.getElementById('trRows').appendChild(row);
        trIdx++;
    }

    // ⚠️ الفورم كله متغلّف بشرط المدير، والراوت مفتوح للكل.
    // من غير الشرط ده أي مندوب يفتح الصفحة ياخد TypeError في الكونسول.
    // (وممنوع نكتب اسم دايركتيف بليد في تعليق — البليد بيقراه ويكسّر الصفحة.)
    if (document.getElementById('trRows')) {
        trAddLine();

        // ⚠️ لازم تتربط بعد ما السطر الأول يتعمل — من غير كده أول
        // تغيير للمخزن بيلاقي جدول فاضي ومابيعملش حاجة، واللي قدامه
        // بيفضل شايف باتشات المخزن القديم.
        const from = document.querySelector('[name="from_warehouse_id"]');

        if (from) {
            from.addEventListener('change', trReloadAll);
        }
    }
</script>
@endsection
