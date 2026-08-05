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
            <input type="file" name="files[]" multiple required accept=".xlsx,.xls" style="width:100%">
            <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('ops.po_files_hint') }}</div>
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
</script>
@endsection
