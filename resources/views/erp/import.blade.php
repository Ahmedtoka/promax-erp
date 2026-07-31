@extends('layouts.system')

@section('title', __('import.page'))

@php
    $fmt = fn ($n) => number_format((float) $n);

    // ترتيب التنفيذ المفروض — الرقم بيظهر جنب كل نوع
    $order = ['products' => 1, 'clients' => 2, 'team' => 3, 'stock' => 4];
@endphp

@section('content')

<div class="card">
    <h3>📥 {{ __('import.page') }} <span class="side">{{ __('import.page_sub') }}</span></h3>
    <div class="alert info">{{ __('import.order_hint') }}</div>
</div>

{{-- ═══════════ إيه الموجود دلوقتي ═══════════ --}}
<div class="kpis">
    @foreach ($kinds as $kind)
        <div class="kpi">
            <div class="lbl">{{ $order[$kind] }}. {{ __('import.kind_'.$kind) }}</div>
            <div class="val {{ ($counts[$kind] ?? 0) > 0 ? 'pos' : 'neg' }}">{{ $fmt($counts[$kind] ?? 0) }}</div>
            <div class="sub2">{{ __('import.rows_in_system') }}</div>
        </div>
    @endforeach
</div>

{{-- ═══════════ الرفع ═══════════ --}}
<div class="card">
    <h3>⬆️ {{ __('import.upload') }}</h3>

    <form method="POST" action="{{ route('erp.import.upload') }}" enctype="multipart/form-data">
        @csrf
        <div class="frow">
            <div>
                <label class="f">{{ __('import.data_kind') }}</label>
                <select name="kind" id="kindSel" required style="width:100%" onchange="showCols(this.value)">
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind }}">{{ $order[$kind] }}. {{ __('import.kind_'.$kind) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('import.file') }}</label>
                <input type="file" name="file" accept=".xlsx,.csv,.txt" required style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('import.sheet_name') }}</label>
                <input type="text" name="sheet" placeholder="{{ __('import.sheet_hint') }}" style="width:100%">
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px">
                <button class="btn gold" type="submit">{{ __('import.read_and_preview') }}</button>
            </div>
        </div>
        <div style="font-size:11.5px;color:var(--muted)">{{ __('import.preview_note') }}</div>
    </form>
</div>

{{-- ═══════════ الأعمدة المتوقّعة لكل نوع ═══════════ --}}
@foreach ($kinds as $kind)
    <div class="card colBox" id="cols-{{ $kind }}" @if (! $loop->first) style="display:none" @endif>
        <h3>📋 {{ __('import.expected_columns') }} — {{ __('import.kind_'.$kind) }}
            <span class="side">{{ __('import.name_match_hint') }}</span>
        </h3>

        <div style="margin-bottom:12px">
            <a class="btn" href="{{ route('erp.import.template', $kind) }}">⬇️ {{ __('import.download_template') }}</a>
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('import.column') }}</th>
                    <th>{{ __('import.accepted_names') }}</th>
                    <th>{{ __('common.status') }}</th>
                </tr>
                @foreach ($columns[$kind]['columns'] as $key => $names)
                    <tr>
                        <td><b>{{ $names[0] }}</b></td>
                        <td style="font-size:11.5px;color:var(--muted)">{{ implode(' · ', $names) }}</td>
                        <td>
                            @if (in_array($key, $columns[$kind]['required'], true))
                                <span class="badge b-red">{{ __('import.required') }}</span>
                            @else
                                <span class="badge b-gray">{{ __('import.optional') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endforeach

{{-- ═══════════ السجل ═══════════ --}}
<div class="card">
    <h3>🕘 {{ __('import.history') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('import.data_kind') }}</th>
                <th>{{ __('import.file') }}</th>
                <th class="num">{{ __('import.rows') }}</th>
                <th class="num">{{ __('import.accepted') }}</th>
                <th class="num">{{ __('import.rejected') }}</th>
                <th>{{ __('common.status') }}</th>
                <th>{{ __('import.result') }}</th>
                <th></th>
            </tr>

            @forelse ($history as $h)
                <tr>
                    <td>{{ $h->kindLabel() }}</td>
                    <td style="max-width:220px;white-space:normal">{{ $h->file_name }}
                        <br><span style="font-size:10px;color:var(--muted)">
                            {{ $h->created_at?->format('Y-m-d H:i') }}
                            @if ($h->user) · {{ $h->user->displayName() }} @endif
                        </span>
                    </td>
                    <td class="num">{{ $fmt($h->rows_total) }}</td>
                    <td class="num pos">{{ $fmt($h->rows_ok) }}</td>
                    <td class="num {{ $h->rows_failed > 0 ? 'neg' : '' }}">{{ $fmt($h->rows_failed) }}</td>
                    <td><span class="badge {{ $h->statusClass() }}">{{ $h->statusLabel() }}</span></td>
                    <td style="font-size:11.5px;color:var(--muted);max-width:280px;white-space:normal">
                        {{ $h->resultLine() ?? '—' }}
                    </td>
                    <td class="num">
                        @if ($h->isPending())
                            <a class="btn sm gold" href="{{ route('erp.import.preview', $h) }}">
                                {{ __('import.review') }}
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('import.no_history') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection

@section('scripts')
<script>
    /* بيوري أعمدة النوع المختار بس */
    function showCols(kind) {
        document.querySelectorAll('.colBox').forEach(el => { el.style.display = 'none'; });
        const box = document.getElementById('cols-' + kind);
        if (box) { box.style.display = ''; }
    }
</script>
@endsection
