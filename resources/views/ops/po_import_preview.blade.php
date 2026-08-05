@extends('layouts.system')

{{--
    معاينة POs المرفوعة — راجع مطابقة كل ملف على فرعه قبل الإنشاء.
    الأصناف اتطابقت بالباركود (وبعدين SKU) — والغير معروف باين صريح.
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

    <form method="POST" action="{{ route('ops.po.import.store') }}">
        @csrf
        <input type="hidden" name="warehouse_id" value="{{ $batch['warehouse_id'] }}">
        <input type="hidden" name="assigned_to" value="{{ $batch['assigned_to'] }}">
        <input type="hidden" name="due_at" value="{{ $batch['due_at'] }}">

        <div class="tablewrap" style="max-height:65vh;overflow-y:auto">
            <table>
                <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
                    <tr>
                        <th style="width:34px"></th>
                        <th>{{ __('ops.po_file') }}</th>
                        <th style="width:120px">{{ __('ops.po_source_no') }}</th>
                        <th style="width:230px">{{ __('ops.branch_client') }}</th>
                        <th>{{ __('stock.item') }}</th>
                        <th class="num" style="width:90px">{{ __('common.qty') }}</th>
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
                        <tr style="{{ $ok ? '' : 'background:#FFF7ED' }}">
                            <td>
                                {{-- استبعاد ملف من الدفعة --}}
                                <input type="checkbox" name="orders[{{ $i }}][skip]" value="1"
                                       title="{{ __('ops.po_skip_file') }}">
                            </td>
                            <td>
                                <b style="font-size:12px">{{ $e['file'] }}</b>
                                <div style="font-size:10.5px;color:var(--muted)">
                                    {{ $e['store_name'] ?? '—' }}
                                    @if ($e['store_id']) · Store {{ $e['store_id'] }} @endif
                                </div>
                                @if ($e['unknown'] !== [])
                                    <div class="badge b-red" style="font-size:10px;margin-top:4px">
                                        {{ __('ops.po_unknown_barcodes', ['codes' => implode('، ', $e['unknown'])]) }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <input type="text" name="orders[{{ $i }}][source]" maxlength="40"
                                       value="{{ $e['po_no'] }}" style="width:100%">
                            </td>
                            <td>
                                <select name="orders[{{ $i }}][client_id]" style="width:100%">
                                    <option value="">— {{ __('ops.po_no_match') }} —</option>
                                    @foreach ($clients as $c)
                                        <option value="{{ $c->id }}" @selected($e['client_id'] === $c->id)>
                                            {{ $c->name }} ({{ $c->code }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="font-size:11.5px">
                                @forelse ($e['items'] as $it)
                                    <div>{{ $it['name'] }} × <b>{{ $fmt($it['qty']) }}</b></div>
                                @empty
                                    <span class="badge b-red">{{ __('ops.po_no_items') }}</span>
                                @endforelse
                                <input type="hidden" name="orders[{{ $i }}][items]" value="{{ $itemsJson }}">
                            </td>
                            <td class="num"><b>{{ $fmt($e['qty_total']) }}</b></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;align-items:center;margin-top:14px">
            <div style="font-size:12px;color:var(--muted)">{{ __('ops.po_confirm_hint') }}</div>
            <button class="btn gold" type="submit">📨 {{ __('ops.po_create_all') }}</button>
        </div>
    </form>
</div>

@endsection
