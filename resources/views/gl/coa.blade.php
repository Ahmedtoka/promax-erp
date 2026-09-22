@extends('layouts.system')

@section('title', __('coa.page'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $f0 = fn ($n) => number_format((float) $n);
@endphp

@section('actions')
    @if ($ready && count($flat))
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    @endif
@endsection

@section('content')

@if (! $ready)
    <div class="card"><p style="color:var(--muted)">⚠️ {{ __('coa.need_migrate') }}</p></div>
@else

<div class="card" style="border-inline-start:4px solid var(--royal-blue)">
    <p style="margin:0;font-size:13px;line-height:1.9">📌 {{ __('coa.intro') }}</p>
</div>

@if (count($flat))
@php $q = fn (?string $s) => request()->fullUrlWithQuery(['show' => $filter === $s ? null : $s, 'export' => null]); @endphp
<div class="kpis">
    <a class="kpi @if(! $filter) on @endif" href="{{ $q(null) }}"><div class="lbl">🌳 {{ __('coa.k_total') }}</div>
        <div class="val">{{ $f0($kpi['total']) }}</div><div class="sub2">{{ __('coa.k_total_hint', ['n' => $kpi['roots']]) }}</div></a>
    <a class="kpi @if($filter === 'nocode') on @endif" href="{{ $q('nocode') }}"><div class="lbl">🔢 {{ __('coa.k_no_code') }}</div>
        <div class="val" style="color:var(--red)">{{ $f0($kpi['no_code']) }}</div><div class="sub2">{{ __('ui.click_to_filter') }}</div></a>
    <a class="kpi @if($filter === 'dup') on @endif" href="{{ $q('dup') }}"><div class="lbl">♊ {{ __('coa.k_dup_codes') }}</div>
        <div class="val" style="color:var(--red)">{{ $f0($kpi['dup_codes']) }}</div><div class="sub2">{{ __('ui.click_to_filter') }}</div></a>
    <a class="kpi @if($filter === 'noar') on @endif" href="{{ $q('noar') }}"><div class="lbl">🔤 {{ __('coa.k_no_ar') }}</div>
        <div class="val">{{ $f0($kpi['no_ar']) }}</div><div class="sub2">{{ __('ui.click_to_filter') }}</div></a>
    <a class="kpi @if($filter === 'feed') on @endif" href="{{ $q('feed') }}"><div class="lbl">🔗 {{ __('coa.k_feed') }}</div>
        <div class="val" style="color:var(--green)">{{ $f0($feeds['sales_ka'] + $feeds['sales_van']) }}</div>
        {{-- الرقم مفسَّر: أوامر + فواتير، والباقي لحد إجمالي كشف الحساب --}}
        <div class="sub2" dir="ltr">{{ $f0($feeds['sales_ka']) }} KA + {{ $f0($feeds['sales_van']) }} Van</div></a>
</div>

{{-- ═══ الربط الوحيد: مبيعات السيستم على حسابين الإيراد — الرقم متفسّر لحد إجمالي كشف الحساب ═══ --}}
<div class="card">
    <h3>🔗 {{ __('coa.feed_title') }} <span class="side">{{ __('coa.feed_side') }}</span></h3>
    <form class="searchbar" method="GET">
        @if ($filter)<input type="hidden" name="show" value="{{ $filter }}">@endif
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
    </form>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:13px" dir="rtl">
        <span class="badge b-blue">🚚 {{ __('coa.feed_sales_ka') }}: <b class="num">{{ $fmt($feeds['sales_ka']) }}</b></span> +
        <span class="badge b-purple">🚐 {{ __('coa.feed_sales_van') }}: <b class="num">{{ $fmt($feeds['sales_van']) }}</b></span> +
        <span class="badge b-gray">✍️ {{ __('coa.feed_other') }}: <b class="num">{{ $fmt($feeds['other']) }}</b></span> +
        <span class="badge b-orange">🏛 {{ __('coa.feed_tax') }}: <b class="num">{{ $fmt($feeds['tax']) }}</b></span> =
        <span class="badge b-green">💰 {{ __('coa.feed_gross') }}: <b class="num">{{ $fmt($feeds['gross']) }}</b></span>
    </div>
    <p style="font-size:11.5px;color:var(--muted);margin:10px 0 0">ℹ️ {{ __('coa.feed_hint') }}</p>
</div>
@endif

<div class="card">
    <h3>🌳 {{ __('coa.tree_title') }}
        <span class="side">
            <button class="btn sm gold" type="button" onclick="coaAdd(null, '')">＋ {{ __('coa.add_root') }}</button>
            <button class="btn sm" type="button" onclick="openDlg('dlgCoaImport')">📥 {{ __('coa.import_btn') }}</button>
        </span></h3>

    @if (! count($flat))
        <p style="color:var(--muted);text-align:center;padding:30px 0">{{ __('coa.empty') }}</p>
    @else
    <div class="searchbar" data-noprint>
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="search" id="coaQ" placeholder="🔍 {{ __('coa.search_ph') }}" autocomplete="off"></label>
        <label class="fl"><span>{{ __('coa.l_levels') }}</span>
            <select id="coaLvl">
                <option value="">{{ __('coa.all_levels') }}</option>
                @for ($l = 1; $l <= 6; $l++)<option value="{{ $l }}">{{ __('coa.up_to_level', ['n' => $l]) }}</option>@endfor
            </select></label>
        <span class="badge b-gray" id="coaSaved" style="align-self:flex-end;margin-bottom:6px">💾 {{ __('coa.autosave') }}</span>
    </div>

    {{-- ⚠️ `data-plain`: جدول إدخال — الترتيب والتقسيم لصفحات بتوع أدوات الجداول كانوا هيلخبطوا الشجرة --}}
    <div class="tablewrap" style="max-height:72vh;overflow:auto">
        <table data-plain data-noxl id="coaTbl" class="coa">
            <thead><tr>
                <th style="width:34px">#</th><th style="width:120px">{{ __('coa.c_code') }}</th><th>{{ __('coa.c_name') }}</th>
                <th>{{ __('coa.c_name_ar') }}</th><th style="width:170px">{{ __('coa.c_type') }}</th>
                <th class="num" style="width:120px">{{ __('coa.c_file_balance') }}</th>
                <th class="num" style="width:130px">{{ __('coa.c_system_sales') }}</th>
                <th style="width:190px">{{ __('coa.c_actions') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($flat as $i => [$a, $depth])
                @php
                    $isDup = $a->code !== null && isset($dupCodes[$a->code]);
                    $hide = match ($filter) {
                        'nocode' => (string) $a->code !== '', 'dup' => ! $isDup,
                        'noar' => (string) $a->name_ar !== '', 'feed' => ! $a->feed, default => false,
                    };
                @endphp
                @continue($hide)
                <tr id="a{{ $a->id }}" data-id="{{ $a->id }}" data-depth="{{ $depth + 1 }}" @if($depth === 0) class="root" @endif>
                    <td style="color:var(--muted);font-size:10.5px" title="{{ __('coa.c_source_row') }}: {{ $a->source_row ?? '—' }}">{{ $i + 1 }}</td>
                    <td><input data-f="code" value="{{ $a->code }}" dir="ltr" placeholder="—" @class(['bad' => $isDup || (string) $a->code === ''])
                               title="{{ $isDup ? __('coa.dup_code') : '' }}"></td>
                    {{-- الأسماء إنجليزي زي الملف — الخانة LTR عشان تدرّج الشجرة يبقى من ناحية بداية الاسم --}}
                    <td dir="ltr"><div style="display:flex;align-items:center;padding-left:{{ $depth * 18 }}px">
                        <span style="color:var(--muted);margin-right:4px">{{ $depth ? '└' : '▸' }}</span>
                        <input data-f="name" value="{{ $a->name }}" dir="ltr" style="@if($depth === 0) font-weight:800 @endif"></div></td>
                    <td><input data-f="name_ar" value="{{ $a->name_ar }}" dir="rtl" placeholder="{{ __('coa.name_ar_ph') }}"></td>
                    <td><input data-f="qb_type" value="{{ $a->qb_type }}" dir="ltr" list="coaTypes"></td>
                    <td class="num" style="font-size:11.5px">{{ $a->balance === null ? '—' : $fmt($a->balance) }}</td>
                    <td class="num" style="font-size:11.5px">
                        @if ($a->feed)<b style="color:var(--green)">{{ $fmt($feeds[$a->feed]) }}</b>
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('coa.feed_'.$a->feed) }}</span>
                        @else — @endif
                    </td>
                    <td style="white-space:nowrap">
                        <form method="POST" action="{{ route('gl.coa.move', $a) }}" style="display:inline">@csrf
                            <button class="btn sm" name="dir" value="up" title="{{ __('coa.move_up') }}">▲</button>
                            <button class="btn sm" name="dir" value="down" title="{{ __('coa.move_down') }}">▼</button>
                        </form>
                        <button class="btn sm" type="button" title="{{ __('coa.move_under') }}"
                                onclick="coaMove({{ $a->id }}, @js(trim($a->code.' '.$a->name)), '{{ $a->feed }}')">↪</button>
                        <button class="btn sm" type="button" title="{{ __('coa.add_child') }}"
                                onclick="coaAdd({{ $a->id }}, @js(trim($a->code.' '.$a->name)))">＋</button>
                        <form method="POST" action="{{ route('gl.coa.destroy', $a) }}" style="display:inline"
                              onsubmit="return confirm(@js(__('coa.confirm_delete')))">@csrf @method('DELETE')
                            <button class="btn sm red" title="{{ __('common.delete') }}">🗑</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <datalist id="coaTypes">@foreach ($types as $t)<option value="{{ $t }}">@endforeach</datalist>
    @endif
</div>

{{-- ═══ رفع الملف ═══ --}}
<dialog id="dlgCoaImport">
    <form class="dlg" method="POST" action="{{ route('gl.coa.import') }}" enctype="multipart/form-data">
        @csrf
        <h3>📥 {{ __('coa.import_title') }}</h3>
        <p style="font-size:12.5px;color:var(--muted);line-height:1.8">{{ __('coa.import_hint') }}</p>
        <label class="f">{{ __('coa.import_file') }}</label>
        <input type="file" name="file" accept=".xlsx,.csv" required>
        @if ($ready && count($flat))
            <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:12.5px">
                <input type="checkbox" name="replace" value="1"> {{ __('coa.import_replace', ['n' => count($flat)]) }}</label>
        @endif
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('coa.import_btn') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ حساب جديد ═══ --}}
<dialog id="dlgCoaAdd">
    <form class="dlg" method="POST" action="{{ route('gl.coa.store') }}">
        @csrf
        <h3>＋ {{ __('coa.add_title') }}</h3>
        <input type="hidden" name="parent_id" id="coaAddParent">
        <p style="font-size:12.5px;color:var(--muted)">{{ __('coa.add_under') }}: <b id="coaAddUnder"></b></p>
        <label class="f">{{ __('coa.c_code') }}</label><input name="code" dir="ltr" maxlength="40">
        <label class="f">{{ __('coa.c_name') }}</label><input name="name" dir="ltr" maxlength="190" required>
        <label class="f">{{ __('coa.c_name_ar') }}</label><input name="name_ar" dir="rtl" maxlength="190">
        <label class="f">{{ __('coa.c_type') }}</label><input name="qb_type" dir="ltr" list="coaTypes" maxlength="60">
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ نقل تحت حساب تاني + ربط مبيعات السيستم ═══ --}}
@if ($ready && count($flat))
<dialog id="dlgCoaMove">
    <form class="dlg" method="POST" id="coaMoveForm">
        @csrf
        <input type="hidden" name="dir" value="parent">
        <h3>↪ {{ __('coa.move_title') }}</h3>
        <p style="font-size:12.5px;color:var(--muted)"><b id="coaMoveName"></b></p>
        <label class="f">{{ __('coa.move_to') }}</label>
        <select name="parent_id">
            <option value="">{{ __('coa.as_root') }}</option>
            @foreach ($flat as [$a, $depth])
                <option value="{{ $a->id }}">{{ str_repeat('— ', $depth) }}{{ trim($a->code.' '.$a->name) }}</option>
            @endforeach
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('coa.move_btn') }}</button>
        </div>
        <hr style="margin:16px 0;border:0;border-top:1px solid var(--border)">
        <label class="f">{{ __('coa.feed_label') }}</label>
        <select id="coaFeedSel">
            <option value="">{{ __('coa.feed_none') }}</option>
            <option value="sales_ka">🚚 {{ __('coa.feed_sales_ka') }}</option>
            <option value="sales_van">🚐 {{ __('coa.feed_sales_van') }}</option>
        </select>
        <p style="font-size:11.5px;color:var(--muted)">{{ __('coa.feed_label_hint') }}</p>
    </form>
</dialog>
@endif

@endif
@endsection

@section('scripts')
@if ($ready ?? false)
<style>
table.coa input{width:100%;border:1px solid transparent;background:transparent;padding:5px 7px;border-radius:7px;font-family:inherit;font-size:12.5px;color:var(--text)}
table.coa input:hover{border-color:var(--border)}
table.coa input:focus{border-color:var(--royal-blue);background:var(--card);outline:none}
table.coa input.bad{background:#FDECEC;border-color:#F3B4B4}
table.coa input.saved{background:#E8F7EE;transition:background .2s}
table.coa tr.root td{background:var(--card2)}
table.coa thead th{position:sticky;top:0;z-index:5}
table.coa td{padding:3px 6px;vertical-align:middle}
</style>
<script>
(function () {
  var URLT = @json(route('gl.coa.update', '__ID__'));
  var MOVE = @json(route('gl.coa.move', '__ID__'));
  var TOKEN = @json(csrf_token());
  var badge = document.getElementById('coaSaved');
  var T = { saving: @json(__('coa.saving')), saved: @json(__('coa.saved')), failed: @json(__('coa.save_failed')) };

  function save(id, field, value, el) {
    if (badge) badge.textContent = '⏳ ' + T.saving;
    return fetch(URLT.replace('__ID__', id), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
      body: JSON.stringify({ field: field, value: value })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok) throw new Error((x.j && x.j.error) || T.failed);
        if (badge) badge.textContent = '✅ ' + T.saved;
        if (el) { el.classList.remove('bad'); el.classList.add('saved'); setTimeout(function () { el.classList.remove('saved'); }, 900); }
      }).catch(function (e) {
        if (badge) badge.textContent = '⚠️ ' + e.message;
        if (el) { el.classList.add('bad'); el.value = el.defaultValue; }
      });
  }

  // الخانة بتتحفظ أول ما تسيبها وقيمتها اتغيرت — مفيش زرار حفظ عشان 270 صف × 4 خانات
  document.querySelectorAll('#coaTbl input[data-f]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var tr = inp.closest('tr');
      save(tr.dataset.id, inp.dataset.f, inp.value, inp).then(function () { inp.defaultValue = inp.value; });
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
  });

  // بحث + إظهار لحد مستوى معيّن
  var q = document.getElementById('coaQ'), lvl = document.getElementById('coaLvl');
  function apply() {
    var s = (q && q.value || '').trim().toLowerCase(), max = parseInt(lvl && lvl.value, 10) || 99;
    document.querySelectorAll('#coaTbl tbody tr').forEach(function (tr) {
      var hay = [].map.call(tr.querySelectorAll('input'), function (i) { return i.value; }).join(' ').toLowerCase();
      tr.style.display = (parseInt(tr.dataset.depth, 10) <= max && (!s || hay.indexOf(s) >= 0)) ? '' : 'none';
    });
  }
  if (q) q.addEventListener('input', apply);
  if (lvl) lvl.addEventListener('change', apply);

  window.coaAdd = function (parentId, label) {
    document.getElementById('coaAddParent').value = parentId || '';
    document.getElementById('coaAddUnder').textContent = label || @json(__('coa.as_root'));
    openDlg('dlgCoaAdd');
  };

  var feedSel = document.getElementById('coaFeedSel'), moving = null;
  window.coaMove = function (id, label, feed) {
    moving = id;
    document.getElementById('coaMoveForm').action = MOVE.replace('__ID__', id);
    document.getElementById('coaMoveName').textContent = label;
    if (feedSel) feedSel.value = feed || '';
    openDlg('dlgCoaMove');
  };
  if (feedSel) feedSel.addEventListener('change', function () {
    if (moving) save(moving, 'feed', feedSel.value, null).then(function () { location.reload(); });
  });
})();
</script>
@endif
@endsection
