@extends('layouts.system')

@section('title', $title)

@section('actions')
    <a class="btn" href="{{ route('erp.reports.hub') }}">← {{ __('rpt.hub_title') }}</a>
    {{-- التصدير بنفس الفلاتر الحالية بالظبط — نفس الكويري ونفس الصفوف --}}
    <a class="btn gold" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇️ {{ __('rpt.export') }}</a>
    <button class="btn" type="button" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

{{-- ═══ الفلاتر (٢٢/٩) — كل دروب داون بعنوانه وأول اختيار «الكل»، والفترة آخر حاجة من `_range` ═══ --}}
<div class="card" style="padding:12px 14px">
    <form class="searchbar" method="GET" action="{{ route('erp.reports.show', $key) }}" style="margin-bottom:0">

        @if (in_array('rep', $filters))
            <label class="fl"><span>{{ __('ui.l_rep') }}</span>
                <select name="user_id">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.reps')]) }}</option>
                    @foreach ($repOptions as $u)
                        <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->displayName() }}</option>
                    @endforeach
                </select></label>
        @endif

        @if (in_array('channel', $filters))
            <label class="fl"><span>{{ __('ui.l_channel') }}</span>
                <select name="channel_id">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.channels')]) }}</option>
                    @foreach ($channelOptions as $ch)
                        <option value="{{ $ch->id }}" @selected(request('channel_id') == $ch->id)>{{ $ch->displayName() }}</option>
                    @endforeach
                </select></label>
        @endif

        @if (in_array('payment', $filters))
            <label class="fl"><span>{{ __('ui.l_payment') }}</span>
                <select name="payment">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.pay_kinds')]) }}</option>
                    <option value="cash" @selected(request('payment') === 'cash')>{{ __('rpt.cash') }}</option>
                    <option value="credit" @selected(request('payment') === 'credit')>{{ __('rpt.credit') }}</option>
                </select></label>
        @endif

        @if (in_array('status', $filters))
            <label class="fl"><span>{{ __('ui.l_status') }}</span>
                <select name="status">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.statuses')]) }}</option>
                    @foreach (\App\Models\PurchaseOrder::STATUSES as $sk => $sv)
                        <option value="{{ $sk }}" @selected(request('status') === $sk)>{{ __('enums.po_status.'.$sk) }}</option>
                    @endforeach
                </select></label>
        @endif

        {{-- فلاتر خاصة بتقرير واحد: الكنترولر بيبعتها `name => [caption, all, options]` --}}
        @foreach ($extraSelects ?? [] as $selName => [$selCaption, $selAll, $selOptions])
            <label class="fl"><span>{{ $selCaption }}</span>
                <select name="{{ $selName }}">
                    <option value="">{{ $selAll }}</option>
                    @foreach ($selOptions as $ov => $ol)
                        <option value="{{ $ov }}" @selected((string) request($selName) === (string) $ov)>{{ $ol }}</option>
                    @endforeach
                </select></label>
        @endforeach

        @if (in_array('days', $filters))
            <label class="fl"><span>{{ $daysLabel ?? __('rpt.f_days') }}</span>
                <input type="number" name="days" min="1" max="365" value="{{ request('days', $daysDefault ?? 14) }}"></label>
        @endif

        @if (in_array('q', $filters))
            <label class="fl wide grow"><span>{{ __('ui.l_search') }}</span>
                <input type="search" name="q" value="{{ request('q') }}" dir="auto"></label>
        @endif

        {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي مش «كل الفترات» (`ReportController::range`) --}}
        @if (in_array('range', $filters))
            @include('partials._range', ['from' => $periodFrom, 'to' => $periodTo, 'all' => false])
        @endif

        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('erp.reports.show', $key) }}">{{ __('common.clear') }}</a>
    </form>
</div>

{{-- ═══ السامري بوكسات ═══ --}}
{{-- الفترة ووقت السحب (٢١/٩) — التقرير المطبوع أو المتصوّر لازم يقول لأنهي فترة واتسحب إمتى --}}
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;font-size:12.5px">
    <span class="badge b-blue">📅 {{ __('common.exp_period') }}:
        <span class="num">{{ $periodFrom ? $periodFrom.' → '.$periodTo : __('common.exp_all_time') }}</span></span>
    <span class="badge b-gray">🕒 {{ __('common.exp_generated') }}: <span class="num">{{ $generatedAt }}</span></span>
</div>

<div class="kpis">
    {{-- (٢٢/٩) الكارت إما فلتر على نفس التقرير (`on` لما يبقى شغال) أو لينك للشاشة اللي بتفرد رقمه --}}
    @foreach ($kpis as $k)
        {{-- (٢٢/٩) العنصر السادس = سطر الشرح: الرقم ده إيه واتحسب إزاي. نص، أو
             `[كلام, معادلة]` والمعادلة بتترسم LTR عشان الأرقام ماتتقلبش. مش بيدخل الـCSV --}}
        @php
            [$lbl, $val, $cls] = $k; $kUrl = $k[3] ?? null;
            $kEx = $k[5] ?? null;
            [$kExText, $kExEq] = is_array($kEx) ? [$kEx[0] ?? '', $kEx[1] ?? ''] : [(string) $kEx, ''];
        @endphp
        @if ($kUrl)
            <a @class(['kpi', 'on' => ! empty($k[4])]) href="{{ $kUrl }}">
                <div class="lbl">{{ $lbl }}</div>
                <div class="val {{ $cls }}">{{ $val }}</div>
                @if ($kExText !== '' || $kExEq !== '')
                    <div class="sub2">{{ $kExText }} @if ($kExEq !== '')<span dir="ltr" class="rpt-eq">{{ $kExEq }}</span>@endif</div>
                @endif
            </a>
        @else
            <div class="kpi">
                <div class="lbl">{{ $lbl }}</div>
                <div class="val {{ $cls }}">{{ $val }}</div>
                @if ($kExText !== '' || $kExEq !== '')
                    <div class="sub2">{{ $kExText }} @if ($kExEq !== '')<span dir="ltr" class="rpt-eq">{{ $kExEq }}</span>@endif</div>
                @endif
            </div>
        @endif
    @endforeach
</div>

{{-- ═══ الجدول — هيدر ثابت + صف إجماليات ═══ --}}
<div class="card">
    <h3>{{ $icon }} {{ $title }}
        <span class="side">{{ __('rpt.rows_n', ['n' => number_format(count($rows))]) }}</span>
    </h3>

    {{-- ملحوظة التقرير (٢٢/٩): الحاجة اللي لازم تتعرف قبل قراية الجدول — زي إن الأرقام
         «لحد النهارده» مش بالفترة، أو إن الإجمالي من مستوى واحد --}}
    @if (! empty($note))
        <div class="rpt-note">ℹ️ {{ $note }}</div>
    @endif

    <div class="tablewrap rpt-wrap">
        <table>
            <thead>
            <tr>
                @foreach ($columns as $c)
                    {{-- ⚠️ `data-nosum` على كل الأعمدة: إجمالي التقرير من السيرفر بس (`totals`) —
                         الجمع الأوتوماتيك كان هيجمع أيام ودقايق ونِسب في التقارير اللي من غير صف إجمالي --}}
                    <th data-nosum @if (($c[1] ?? null) === 'num') class="num" @endif>{{ $c[0] }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        {{-- الخلية نص عادي أو `['text' => …, 'url' => …]` — اللي بيسمّي سجل بيفتحه --}}
                        <td @if (($columns[$i][1] ?? null) === 'num') class="num" dir="ltr" @endif>@if (is_array($cell) && ! empty($cell['url']))<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@elseif (is_array($cell) && ! empty($cell['badge']))<span class="badge {{ $cell['badge'] }}">{{ $cell['text'] ?? '' }}</span>@else{{ is_array($cell) ? ($cell['text'] ?? '') : $cell }}@endif</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" style="text-align:center;color:var(--muted);padding:26px">
                    {{ __('rpt.no_rows') }}</td></tr>
            @endforelse
            </tbody>
            @if (! empty($totals))
                <tfoot>
                <tr class="rpt-total">
                    @foreach ($totals as $i => $cell)
                        <td @if (($columns[$i][1] ?? null) === 'num') class="num" dir="ltr" @endif>{{ $cell }}</td>
                    @endforeach
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

@endsection

@section('scripts')
<style>
/* هيدر ثابت مع التمرير — طلب المالك بالنص */
.rpt-wrap{max-height:68vh;overflow:auto}
.rpt-wrap thead th{
  position:sticky;top:0;z-index:3;
  background:var(--royal-blue);color:#fff;
}
.rpt-total td{
  position:sticky;bottom:0;z-index:2;
  background:var(--blue-050);font-weight:900;color:var(--royal-blue);
  border-top:2px solid var(--royal-blue);
}
.rpt-note{background:var(--blue-050);border:1px solid var(--border);border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:12.5px;line-height:1.7}
/* معادلة الكارت: LTR وسطر لوحدها عشان الأرقام تتقري بترتيبها (٢٢/٩) */
.kpi .sub2{line-height:1.6}
.kpi .sub2 .rpt-eq{display:block;unicode-bidi:isolate;font-variant-numeric:tabular-nums;text-align:start}
[dir="rtl"] .kpi .sub2 .rpt-eq{text-align:right}
@media print{.rpt-wrap{max-height:none;overflow:visible}}
</style>
@endsection
