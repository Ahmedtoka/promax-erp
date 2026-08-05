@extends('layouts.system')

{{--
    رفع POs من شيتات السلاسل (2026-08-05) — bulk:
    قناة + مخزن + مندوب + معاد ← ملفات متعددة (ملف = أمر لفرع)
    ← معاينة بمطابقة الفروع ← إنشاء ← طابور موافقات الحسابات.
--}}

@section('title', __('ops.po_import'))

@section('actions')
    <a class="btn" href="{{ route('ops.pos') }}">← {{ __('ops.purchase_orders') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>⬆️ {{ __('ops.po_import') }}
        <span class="side">{{ __('ops.po_import_hint') }}</span></h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('ops.po.import.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="frow">
            <div>
                <label class="f">{{ __('client.channel') }} <b class="req-star">*</b></label>
                <select name="channel_id" id="piChannel" required style="width:100%" onchange="piFilterGroups()">
                    @foreach ($channels as $ch)
                        <option value="{{ $ch->id }}" @selected(old('channel_id') == $ch->id)>{{ $ch->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                {{-- السلسلة (العميل الأساسي) — بتتفلتر بالقناة وبتحصر
                     ديتكشن الفروع في فروعها --}}
                <label class="f">{{ __('ops.po_parent_client') }}</label>
                <select name="group_id" id="piGroup" style="width:100%">
                    <option value="">— {{ __('ops.po_whole_channel') }} —</option>
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.warehouse') }} <b class="req-star">*</b></label>
                <select name="warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected(old('warehouse_id') == $w->id)>{{ $w->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('ops.rep') }} <b class="req-star">*</b></label>
                <select name="assigned_to" required style="width:100%">
                    <option value="">—</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('assigned_to') == $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('ops.due_at') }} <b class="req-star">*</b></label>
                <input type="datetime-local" name="due_at" required style="width:100%" value="{{ old('due_at') }}">
            </div>
        </div>

        <div style="margin-top:12px">
            <label class="f">{{ __('ops.po_files') }} <b class="req-star">*</b></label>
            {{-- الانبوت الخام مخفي — الرفع بمنطقة متصممة وشيبس بأسماء الملفات --}}
            <input type="file" name="files[]" id="piFiles" multiple required accept=".xlsx,.xls"
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden"
                   onchange="piShowFiles()">
            <label for="piFiles" id="piDrop"
                   style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;
                          border:2px dashed var(--border);border-radius:14px;padding:26px 16px;cursor:pointer;
                          background:var(--card2, #fafbff);text-align:center;transition:border-color .15s">
                <span style="font-size:30px">📂</span>
                <span class="btn gold" style="pointer-events:none">⬆️ {{ __('ops.po_pick_files') }}</span>
                <span style="font-size:11px;color:var(--muted)">{{ __('ops.po_files_hint') }}</span>
            </label>
            {{-- شيبس الملفات المختارة — أيقونة + اسم + حجم --}}
            <div id="piChips" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px"></div>
            <div id="piCount" style="font-size:11.5px;font-weight:800;color:var(--royal-blue);margin-top:6px"></div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:14px">
            <button class="btn gold" type="submit">🔍 {{ __('ops.po_import_preview') }}</button>
        </div>
    </form>
</div>

@endsection

@section('scripts')
@php
    // سلاسل كل قناة + أسماء السلاسل — للفلترة في المتصفح
    $piData = json_encode([
        'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->displayName()])->values(),
        'map' => $groupChannels->groupBy('channel_id')
            ->map(fn ($rows) => $rows->pluck('group_id')->values()),
        'old' => (int) old('group_id', 0),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
<script>
const PI = {!! $piData !!};
const PI_ALL = @json('— '.__('ops.po_whole_channel').' —');

/** سلاسل القناة المختارة بس — والباقي بيختفي */
function piFilterGroups() {
    const channel = document.getElementById('piChannel').value;
    const sel = document.getElementById('piGroup');
    const allowed = (PI.map[channel] || []);
    const current = sel.value;

    sel.innerHTML = '';
    const none = document.createElement('option');
    none.value = '';
    none.textContent = PI_ALL;
    sel.appendChild(none);

    PI.groups.filter(g => allowed.includes(g.id)).forEach(g => {
        const opt = document.createElement('option');
        opt.value = g.id;
        opt.textContent = g.name;
        sel.appendChild(opt);
    });

    sel.value = current && allowed.includes(Number(current)) ? current : '';
}

piFilterGroups();
if (PI.old) { document.getElementById('piGroup').value = String(PI.old); }

// ═══ شيبس الملفات المختارة — أيقونة صغيرة + الاسم + الحجم ═══
const PI_COUNT_TPL = @js(__('ops.po_files_count', ['count' => '#N#']));
const piEsc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

function piShowFiles() {
    const files = [...document.getElementById('piFiles').files];
    const chips = document.getElementById('piChips');

    chips.innerHTML = files.map(f =>
        '<span style="display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);' +
        'border-radius:10px;padding:6px 10px;font-size:11.5px;background:#fff;max-width:260px">' +
        '<span style="font-size:15px">📄</span>' +
        '<b style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + piEsc(f.name) + '">' + piEsc(f.name) + '</b>' +
        '<span style="color:var(--muted);flex-shrink:0" dir="ltr">' + (f.size / 1024).toFixed(0) + ' KB</span>' +
        '</span>').join('');

    document.getElementById('piCount').textContent =
        files.length ? PI_COUNT_TPL.replace('#N#', files.length) : '';
    document.getElementById('piDrop').style.borderColor = files.length ? 'var(--royal-blue)' : 'var(--border)';
}
</script>
@endsection
