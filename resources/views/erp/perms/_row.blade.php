{{-- سطر واحد في المصفوفة (صفحة depth=1، شاشة/زرار depth=2). `$r` = صف من PermissionMatrix --}}
@php
    // الصفحات العامة والشاشات مالهاش ليبل — الوصف هو الاسم والمفتاح تحته
    [$title, $withWhat] = \App\Http\Controllers\PermissionController::rowTitle($r);
    $bare = ! $withWhat;
    $kidScreens = count(array_filter($kids, fn ($c) => $c['kind'] === 'screen'));
    $kidActions = count($kids) - $kidScreens;
    $mark = ['screen' => '🖥', 'action' => '🔘'][$r['kind']] ?? null;
@endphp
{{-- ⚠️ `data-pm-row` على السطر اللي ليه تحكم بس — المقفول `data-locked` (فلتر «المتحدد بس» والعدّاد بيعتمدوا عليه) --}}
<div class="pm-row d{{ $depth }}" data-key="{{ $r['key'] }}" {{ $r['locked'] ? 'data-locked' : 'data-pm-row' }} data-kind="{{ $r['kind'] }}" data-risk="{{ $r['risk'] }}"
     data-ov="{{ $r['override'] === null ? '' : ($r['override'] ? '1' : '0') }}">
    <div style="min-width:0">
        <div class="pm-name" data-dirty="{{ __('perm.changed') }}">
            @if ($depth === 1 && $r['icon'])<span aria-hidden="true">{{ $r['icon'] }}</span>@endif
            @if ($mark)<span aria-hidden="true" title="{{ __('perm.kind_'.$r['kind']) }}">{{ $mark }}</span>@endif
            <b>{{ $title }}</b>
            <span class="pm-key">{{ $r['key'] }}</span>
            @if ($kids !== [])
                <button type="button" class="pm-car" aria-expanded="false"><span class="pm-chev" aria-hidden="true">▸</span> {{ trim(($kidScreens ? __('perm.n_screens', ['n' => $kidScreens]) : '').($kidScreens && $kidActions ? ' · ' : '').($kidActions ? __('perm.n_actions', ['n' => $kidActions]) : '')) }}</button>
            @endif
        </div>
        @if (! $bare || $r['data'])
            <div class="pm-what">
                @unless ($bare){{ $r['what'] }}@endunless
                @if ($r['data'])
                    <button type="button" class="pm-more" aria-expanded="false">{{ __('perm.details') }}</button>
                @endif
            </div>
            @if ($r['data'])
                <div class="pm-data" hidden><b>{{ __('perm.data_label') }}:</b> {{ $r['data'] }}</div>
            @endif
        @endif
    </div>
    <div class="pm-meta">
        {!! $risk($r['risk']) !!}
        {!! $src($r['source'], $r['via']) !!}
    </div>
    <div>{!! $dot($r['effective']) !!}</div>
    <div class="pm-ctl">
        @if ($r['locked'])
            <span class="pm-lock">🔒 {{ __('perm.locked_reason') }}</span>
        @else
            {!! $seg($r['key'], $r['override'], isset($r['page']) ? $r['page'].' · '.$title : $title) !!}
        @endif
    </div>
</div>
