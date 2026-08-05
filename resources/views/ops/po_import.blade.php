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
                <select name="channel_id" required style="width:100%">
                    @foreach ($channels as $ch)
                        <option value="{{ $ch->id }}" @selected(old('channel_id') == $ch->id)>{{ $ch->displayName() }}</option>
                    @endforeach
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
