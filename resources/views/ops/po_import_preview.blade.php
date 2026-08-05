@extends('layouts.system')

{{--
    معاينة POs المرفوعة — راجع مطابقة كل ملف على فرعه قبل الإنشاء.
    الأصناف اتطابقت بالباركود (وبعدين SKU) — والغير معروف باين صريح.

    الإنشاء (2026-08-06): متتابع بالـAJAX — أمر ورا الثاني ببروجريس
    بار من 0 لـ100 وحالة لكل صف (✅ برقم الأمر / ❌ بسبب الرفض)،
    وفي الآخر زراير طباعة الكل والذهاب للموافقات.
--}}

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('title', __('ops.po_import'))

@section('actions')
    <a class="btn" href="{{ route('ops.po.import') }}">← {{ __('ops.po_import') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🔍 {{ __('ops.po_import_preview') }}
        <span class="side">{{ __('ops.po_preview_hint') }}</span></h3>

    {{-- ═══ البروجريس — مخفي لحد ما الإنشاء يبدأ ═══ --}}
    <div id="piProg" hidden style="margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:12.5px;font-weight:800">
            <span id="piProgLbl">{{ __('ops.po_creating') }}</span>
            <span id="piProgCount" class="num" dir="ltr" style="margin-inline-start:auto"></span>
            <span id="piProgPct" class="num" dir="ltr">0%</span>
        </div>
        <div style="height:12px;border-radius:8px;background:var(--card2, #eee);overflow:hidden;border:1px solid var(--border)">
            <div id="piProgBar" style="height:100%;width:0%;background:var(--brand-gradient, var(--royal-blue));transition:width .3s"></div>
        </div>
    </div>

    <input type="hidden" id="piWh" value="{{ $batch['warehouse_id'] }}">
    <input type="hidden" id="piRep" value="{{ $batch['assigned_to'] }}">
    <input type="hidden" id="piDue" value="{{ $batch['due_at'] }}">

    <div class="tablewrap" style="max-height:65vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
                <tr>
                    <th style="width:34px"></th>
                    <th>{{ __('ops.po_file') }}</th>
                    <th style="width:120px">{{ __('ops.po_source_no') }}</th>
                    <th style="width:230px">{{ __('ops.branch_client') }}</th>
                    {{-- معاد التوريد لكل أمر لوحده (2026-08-06) — متملي بمعاد الدفعة --}}
                    <th style="width:175px">{{ __('ops.due_at') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th class="num" style="width:90px">{{ __('common.qty') }}</th>
                    <th style="width:150px">{{ __('common.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $i => $e)
                    @php
                        $itemsJson = json_encode(
                            collect($e['items'])->mapWithKeys(fn ($it) => [(string) $it['product_id'] => (int) $it['qty']]),
                            JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP,
                        );
                        $ok = $e['client_id'] !== null && $e['items'] !== [];
                    @endphp
                    @php $isDup = $e['dup'] ?? null; @endphp
                    <tr class="pi-row" id="piRow{{ $i }}"
                        style="{{ $isDup ? 'background:#FEF2F2;opacity:.8' : ($ok ? '' : 'background:#FFF7ED') }}">
                        <td>
                            {{-- استبعاد ملف من الدفعة — المكرر متعلّم أوتوماتيك --}}
                            <input type="checkbox" data-pi="skip" value="1" @checked($isDup)
                                   title="{{ __('ops.po_skip_file') }}">
                        </td>
                        <td>
                            <b style="font-size:12px">{{ $e['file'] }}</b>
                            <div style="font-size:10.5px;color:var(--muted)">
                                {{ $e['store_name'] ?? '—' }}
                                @if ($e['store_id']) · Store {{ $e['store_id'] }} @endif
                            </div>
                            {{-- مرفوع قبل كده — نفس رقم PO لنفس الفرع (2026-08-06) --}}
                            @if ($isDup)
                                <div class="badge b-red" style="font-size:10px;margin-top:4px">
                                    ⛔ {{ __('ops.po_dup_badge', ['number' => $isDup]) }}
                                </div>
                            @endif
                            @if ($e['unknown'] !== [])
                                <div class="badge b-red" style="font-size:10px;margin-top:4px">
                                    {{ __('ops.po_unknown_barcodes', ['codes' => implode('، ', $e['unknown'])]) }}
                                </div>
                            @endif
                        </td>
                        <td><input type="text" data-pi="source" maxlength="40" value="{{ $e['po_no'] }}" style="width:100%"></td>
                        <td>
                            <select data-pi="client" style="width:100%">
                                <option value="">— {{ __('ops.po_no_match') }} —</option>
                                @foreach ($clients as $c)
                                    <option value="{{ $c->id }}" @selected($e['client_id'] === $c->id)>
                                        {{ $c->name }} ({{ $c->code }})
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="datetime-local" data-pi="due" style="width:100%"
                                   value="{{ \Illuminate\Support\Carbon::parse($batch['due_at'])->format('Y-m-d\\TH:i') }}">
                        </td>
                        <td style="font-size:11.5px">
                            @forelse ($e['items'] as $it)
                                <div>{{ $it['name'] }} × <b>{{ $fmt($it['qty']) }}</b></div>
                            @empty
                                <span class="badge b-red">{{ __('ops.po_no_items') }}</span>
                            @endforelse
                            <input type="hidden" data-pi="items" value="{{ $itemsJson }}">
                            <input type="hidden" data-pi="sheet" value="{{ $e['sheet_path'] }}">
                            <input type="hidden" data-pi="sheetname" value="{{ $e['file'] }}">
                        </td>
                        <td class="num"><b>{{ $fmt($e['qty_total']) }}</b></td>
                        <td id="piSt{{ $i }}" style="font-size:11.5px">—</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;align-items:center;margin-top:14px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--muted)">{{ __('ops.po_confirm_hint') }}</div>
        {{-- زراير النهاية — بتظهر بعد ما الدفعة تخلص --}}
        <a class="btn" id="piPrintAll" hidden target="_blank">🖨️ {{ __('ops.print_all') }}</a>
        <a class="btn" id="piGoApprovals" hidden href="{{ route('ops.po.approvals') }}">🔏 {{ __('nav.po_approvals') }}</a>
        <button class="btn gold" type="button" id="piCreateBtn" onclick="piRun()">📨 {{ __('ops.po_start_create') }}</button>
    </div>
</div>

@endsection

@section('scripts')
<script>
const PI_URL = @js(route('ops.po.import.one'));
const PI_PRINT = @js(route('ops.po.print.batch'));
const PI_CSRF = @js(csrf_token());
const PI_SKIPPED = @js(__('ops.po_skipped'));
const PI_CREATED = @js(__('ops.po_created_lbl'));
const PI_OF = @js(__('ops.po_progress_of', ['done' => '#D#', 'total' => '#T#']));
const PI_DONE = @js(__('ops.po_all_done', ['ok' => '#O#', 'fail' => '#F#']));

const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

/** الإنشاء المتتابع: أمر ورا الثاني — البار بيتحرك مع كل واحد */
async function piRun() {
    const rows = [...document.querySelectorAll('tr.pi-row')];
    const jobs = [];

    rows.forEach((tr, i) => {
        const st = document.getElementById('piSt' + i);
        const skip = tr.querySelector('[data-pi="skip"]').checked;
        const client = tr.querySelector('[data-pi="client"]').value;
        const items = tr.querySelector('[data-pi="items"]').value;

        if (skip || !client || items === '' || items === '{}' || items === '[]') {
            st.innerHTML = '<span class="badge b-gray">' + esc(PI_SKIPPED) + '</span>';
            tr.style.opacity = '.55';
            return;
        }

        jobs.push({ i, tr, st, payload: {
            client_id: client,
            source: tr.querySelector('[data-pi="source"]').value,
            items,
            sheet_path: tr.querySelector('[data-pi="sheet"]').value,
            sheet_name: tr.querySelector('[data-pi="sheetname"]').value,
            warehouse_id: document.getElementById('piWh').value,
            assigned_to: document.getElementById('piRep').value,
            // معاد الصف لو اتكتب — وإلا معاد الدفعة الافتراضي
            due_at: tr.querySelector('[data-pi="due"]').value || document.getElementById('piDue').value,
        }});
    });

    if (!jobs.length) return;

    // اقفل الشاشة على الدفعة — مفيش تعديل ولا ضغطة ثانية في النص
    document.getElementById('piCreateBtn').disabled = true;
    document.querySelectorAll('tr.pi-row input, tr.pi-row select').forEach(el => el.disabled = true);
    document.getElementById('piProg').hidden = false;

    let done = 0, ok = 0, fail = 0;
    const createdIds = [];

    for (const job of jobs) {
        job.st.textContent = '⏳';

        try {
            const res = await fetch(PI_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': PI_CSRF },
                body: JSON.stringify(job.payload),
            });
            const body = await res.json().catch(() => ({}));

            if (res.ok && body.ok) {
                ok++;
                createdIds.push(body.id);
                job.st.innerHTML = '✅ <b dir="ltr">' + esc(body.number) + '</b>'
                    + '<div style="font-size:10px;color:var(--muted)">' + esc(PI_CREATED) + '</div>';
                job.tr.style.background = '#F0FDF4';
            } else {
                fail++;
                job.st.innerHTML = '❌ <span style="color:#B00020;font-size:10.5px">'
                    + esc(body.message || res.status) + '</span>';
                job.tr.style.background = '#FEF2F2';
            }
        } catch (e) {
            fail++;
            job.st.innerHTML = '❌ <span style="color:#B00020;font-size:10.5px">' + esc(e.message) + '</span>';
            job.tr.style.background = '#FEF2F2';
        }

        done++;
        const pct = Math.round(done / jobs.length * 100);
        document.getElementById('piProgBar').style.width = pct + '%';
        document.getElementById('piProgPct').textContent = pct + '%';
        document.getElementById('piProgCount').textContent = PI_OF.replace('#D#', done).replace('#T#', jobs.length);
    }

    // خلصنا — السامري وزراير ما بعد الدفعة
    document.getElementById('piProgLbl').textContent = PI_DONE.replace('#O#', ok).replace('#F#', fail);
    document.getElementById('piGoApprovals').hidden = false;

    if (createdIds.length) {
        // طباعة الكل بتفتح أوتوماتيك (قرار المالك 2026-08-06):
        // auto=1 بتشغّل window.print هناك، وback=pos بترجّع لأوامر
        // التوريد بعد الطباعة أو الإلغاء. ثانية ونص عشان السامري يبان.
        const url = PI_PRINT + '?ids=' + createdIds.join(',') + '&back=pos&auto=1';
        const btn = document.getElementById('piPrintAll');
        btn.href = url;
        btn.hidden = false;
        document.getElementById('piProgLbl').textContent += ' — ' + @js(__('ops.po_opening_print'));
        setTimeout(() => { window.location.href = url; }, 1500);
    }
}
</script>
@endsection
