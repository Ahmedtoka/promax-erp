@extends('layouts.system')

@section('title', __('import.preview'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ ممنوع اسم $errors: ده اسم حقيبة أخطاء لارافيل اللي الليّاوت
    // بينادي عليها ->any()، والفيو الابن بيمرّر متغيّراته لليّاوت فبيغطّي
    // عليها بمصفوفة عادية والصفحة بترمي 500.
    $rowErrors = $import->errors ?? [];
    $mapped = $import->summary['mapped'] ?? [];
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.import') }}">← {{ __('import.page') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🔍 {{ __('import.preview') }}
        <span class="side">{{ $import->kindLabel() }} · {{ $import->file_name }}</span>
    </h3>

    {{-- ⚠️ الرسالة دي أهم حاجة في الصفحة: اليوزر لازم يفهم إن لسه
         مفيش حاجة اتكتبت، وإن الضغط تحت هو اللي بيكتب. --}}
    @if ($import->isPending())
        <div class="alert warn">{{ __('import.nothing_written_yet') }}</div>
    @else
        <div class="alert info">{{ __('import.already_applied') }}</div>
    @endif
</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('import.rows') }}</div>
        <div class="val">{{ $fmt($import->rows_total) }}</div>
        <div class="sub2">{{ __('import.in_sheet') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('import.accepted') }}</div>
        <div class="val pos">{{ $fmt($import->rows_ok) }}</div>
        <div class="sub2">{{ __('import.will_be_imported') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('import.rejected') }}</div>
        <div class="val {{ $import->rows_failed > 0 ? 'neg' : '' }}">{{ $fmt($import->rows_failed) }}</div>
        <div class="sub2">{{ __('import.will_be_skipped') }}</div>
    </div>
</div>

{{-- ═══════════ مطابقة الأعمدة ═══════════ --}}
<div class="card">
    <h3>🔗 {{ __('import.column_match') }}</h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($columns as $key)
            @if (isset($mapped[$key]))
                <span class="badge b-green">{{ $key }} ← {{ $mapped[$key] }}</span>
            @else
                <span class="badge b-gray">{{ $key }} — {{ __('import.not_in_sheet') }}</span>
            @endif
        @endforeach
    </div>
</div>

{{-- ═══════════ الأخطاء ═══════════ --}}
@if (count($rowErrors) > 0)
<div class="card">
    <h3>⚠️ {{ __('import.errors') }} <span class="side">{{ count($rowErrors) }}</span></h3>
    <div class="alert warn">{{ __('import.errors_hint') }}</div>
    <ol style="margin-inline-start:20px;font-size:12.5px;line-height:1.9;max-height:340px;overflow:auto">
        @foreach ($rowErrors as $e)
            <li>{{ $e }}</li>
        @endforeach
    </ol>
</div>
@endif

{{-- ═══════════ معاينة الصفوف ═══════════ --}}
@if (count($sample) > 0)
<div class="card">
    <h3>👁️ {{ __('import.sample') }} <span class="side">{{ __('import.first_rows') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th class="num">#</th>
                @foreach ($columns as $key)
                    <th>{{ $key }}</th>
                @endforeach
            </tr>
            @foreach ($sample as $i => $row)
                <tr>
                    <td class="num">{{ $i + 2 }}</td>
                    @foreach ($columns as $key)
                        <td style="white-space:normal;max-width:200px">{{ $row[$key] ?? '—' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

{{-- ═══════════ التأكيد ═══════════ --}}
@if ($import->isPending() && $import->rows_ok > 0)
<div class="card" style="text-align:center">
    <form method="POST" action="{{ route('erp.import.apply', $import) }}"
          onsubmit="return confirm(APPLY_CONFIRM)">
        @csrf
        <button class="btn gold" style="padding:12px 28px;font-size:14px">
            ✅ {{ __('import.apply_now', ['rows' => $fmt($import->rows_ok)]) }}
        </button>
    </form>
    <div style="font-size:11.5px;color:var(--muted);margin-top:8px">{{ __('import.apply_note') }}</div>
</div>
@elseif ($import->isPending())
    <div class="card"><div class="alert warn">{{ __('import.nothing_valid') }}</div></div>
@endif

@endsection

@section('scripts')
<script>
    {{-- ⚠️ في ثابت مش جوه onsubmit — الأبوستروف بيكسّر الجافاسكريبت --}}
    const APPLY_CONFIRM = @js(__('import.apply_confirm', ['rows' => $import->rows_ok]));
</script>
@endsection
