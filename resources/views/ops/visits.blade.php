@extends('layouts.system')

@section('title', __('nav.visits'))

{{--
    «الزيارات» (١٥ أغسطس ٢٠٢٦) — بلاغ المالك: «مش شايف الزيارات اللي
    اتعملت، وفيه مناديب صوّروا الرف قبل وبعد ومفيش حاجة أشوفها فيها».

    الشاشة بتقول: مين زار مين، امتى، قعد قد إيه، وطلع من الزيارة إيه
    (فاتورة/تحصيل/مرتجع/صور رف/هدية/طلب بضاعة) — وزيارة بلا نتيجة
    خالص بتتعدّ في الـKPIs عشان تبان.

    كل الأوقات h:i A بتوقيت القاهرة صراحةً (اللايف سيرفر ممكن يكون
    ناسي APP_TIMEZONE)، والفلوس بالـgrand_total بمنزلتين.
--}}

@php
    $hia = fn ($dt) => $dt?->copy()->timezone('Africa/Cairo')->format('h:i A');
    $fm2 = fn ($n) => number_format((float) $n, 2);
    $blank = \App\Support\VisitOutcomes::blank();

    // حمولة المودال — json_encode مرة واحدة، مش دايركتيف بمصفوفة
    $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

    $modalLabels = json_encode([
        'before' => __('field.shelf_before'),
        'after' => __('field.shelf_after'),
        'invoices' => __('ops.vb_invoices'),
        'collections' => __('ops.vb_collections'),
        'returns' => __('ops.vb_returns'),
        'gifts' => __('ops.vb_gifts'),
        'goods' => __('ops.vb_goods'),
        'note' => __('ops.vb_note'),
        'duration' => __('ops.vb_duration'),
        'in' => __('ops.check_in'),
        'out' => __('ops.check_out'),
        'map' => __('ops.vb_map'),
        'client_card' => __('ops.vb_client_card'),
        'nothing' => __('ops.vb_nothing'),
        'minutes' => __('ops.minutes', ['count' => ':n']),
        'open' => __('ops.in_progress'),
        'photos' => __('field.shelf_photos'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

@section('content')

{{-- ═══════════ KPIs — كلها من نفس الكويري المفلترة ═══════════ --}}
<div class="kpis" style="margin-bottom:14px">
    <div class="kpi">
        <div class="lbl">🚪 {{ __('ops.vb_kpi_visits') }}</div>
        <div class="val">{{ number_format($kpi['visits']) }}</div>
        <div class="sub2">{{ __('ops.vb_kpi_open', ['count' => $kpi['open']]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🏬 {{ __('ops.vb_kpi_clients') }}</div>
        <div class="val">{{ number_format($kpi['clients']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">⏱️ {{ __('ops.vb_kpi_avg') }}</div>
        <div class="val">{{ __('ops.minutes', ['count' => $kpi['avg_min']]) }}</div>
        <div class="sub2">{{ __('ops.vb_kpi_avg_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📸 {{ __('ops.vb_kpi_photos') }}</div>
        <div class="val {{ $kpi['photos'] > 0 ? 'pos' : '' }}">{{ number_format($kpi['photos']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🧾 {{ __('ops.vb_kpi_invoiced') }}</div>
        <div class="val pos">{{ number_format($kpi['invoiced']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🚫 {{ __('ops.vb_kpi_wasted') }}</div>
        <div class="val {{ $kpi['wasted'] > 0 ? 'neg' : 'pos' }}">{{ number_format($kpi['wasted']) }}</div>
        <div class="sub2">{{ __('ops.vb_kpi_wasted_hint') }}</div>
    </div>
</div>

<div class="card">
    <h3>🚪 {{ __('nav.visits') }}
        <span class="side">{{ __('ops.vb_hint') }}</span></h3>

    <form class="searchbar" method="GET">
        <div>
            <label class="f">{{ __('ops.vb_from') }}</label>
            <input type="date" name="from" value="{{ $from->toDateString() }}">
        </div>
        <div>
            <label class="f">{{ __('ops.vb_to') }}</label>
            <input type="date" name="to" value="{{ $to->toDateString() }}">
        </div>
        <div>
            <label class="f">{{ __('ops.rep') }}</label>
            <select name="user">
                <option value="">{{ __('ops.vb_all_reps') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected((int) $filters['user'] === (int) $r->id)>
                        {{ $r->displayName() }} — {{ $r->roleLabel() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('client.client') }}</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('common.search') }}">
        </div>
        <div>
            <label class="f">{{ __('client.zone') }}</label>
            <select name="zone">
                <option value="">{{ __('ops.vb_all_zones') }}</option>
                @foreach ($zones as $z)
                    <option value="{{ $z->id }}" @selected((int) $filters['zone'] === (int) $z->id)>
                        {{ $z->displayName() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('common.status') }}</label>
            <select name="status">
                <option value="">{{ __('common.all') }}</option>
                <option value="closed" @selected($filters['status'] === 'closed')>{{ __('ops.vb_st_closed') }}</option>
                <option value="open" @selected($filters['status'] === 'open')>{{ __('ops.vb_st_open') }}</option>
            </select>
        </div>
        <label class="f" style="display:flex;gap:5px;align-items:center">
            <input type="checkbox" name="has_photos" value="1" @checked($filters['has_photos'])>
            📸 {{ __('ops.vb_f_photos') }}
        </label>
        <label class="f" style="display:flex;gap:5px;align-items:center">
            <input type="checkbox" name="has_invoice" value="1" @checked($filters['has_invoice'])>
            🧾 {{ __('ops.vb_f_invoice') }}
        </label>
        <label class="f" style="display:flex;gap:5px;align-items:center">
            <input type="checkbox" name="has_collection" value="1" @checked($filters['has_collection'])>
            💵 {{ __('ops.vb_f_collection') }}
        </label>
        <label class="f" style="display:flex;gap:5px;align-items:center">
            <input type="checkbox" name="has_return" value="1" @checked($filters['has_return'])>
            ↩️ {{ __('ops.vb_f_return') }}
        </label>
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.visits') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('ops.visit_countable', ['count' => $visits->total()]) }}</span>
    </form>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('ops.rep') }}</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th data-nosum>{{ __('ops.check_in') }}</th>
                <th data-nosum>{{ __('ops.check_out') }}</th>
                <th data-nosum>{{ __('ops.vb_duration') }}</th>
                <th data-nosum>{{ __('ops.vb_outcome') }}</th>
                <th data-nosum>{{ __('ops.vb_location') }}</th>
                <th data-nosum></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($visits as $v)
                @php $o = $out[$v->id] ?? $blank; @endphp
                <tr>
                    <td>
                        <div style="display:flex;gap:9px;align-items:center">
                            @if ($v->user)
                                @include('partials._avatar', ['u' => $v->user, 'size' => 30])
                            @endif
                            <div>
                                <b>{{ $v->user?->displayName() ?? '—' }}</b>
                                <div style="font-size:10.5px;color:var(--muted)">{{ $v->user?->roleLabel() }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        @if ($v->client_id)
                            <a href="{{ route('erp.clients.show', $v->client_id) }}">
                                <b>{{ $v->client?->displayName() ?? '—' }}</b>
                            </a>
                        @else
                            <b>—</b>
                        @endif
                        @if ($v->client?->group)
                            <div style="font-size:10.5px;color:var(--muted)">🏬 {{ $v->client->group->displayName() }}</div>
                        @endif
                    </td>
                    <td class="s">{{ $v->client?->zone?->displayName() ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $hia($v->checked_in_at) ?: '—' }}</td>
                    <td class="num s" dir="ltr">
                        @if ($v->checked_out_at)
                            {{ $hia($v->checked_out_at) }}
                        @else
                            <span class="badge b-orange">{{ __('ops.in_progress') }}</span>
                        @endif
                    </td>
                    <td class="num s">
                        {{ $v->minutes() !== null ? __('ops.minutes', ['count' => $v->minutes()]) : '—' }}
                    </td>
                    <td style="white-space:normal;max-width:320px">
                        @if ($o['inv_count'] > 0)
                            @foreach ($o['invoices'] as $iv)
                                <a class="badge b-green" href="{{ route('ops.invoice', $iv->id) }}"
                                   style="text-decoration:none">🧾 {{ $iv->number }} · {{ $fm2($iv->grand_total) }}</a>
                            @endforeach
                        @endif
                        @if ($o['coll_count'] > 0)
                            <span class="badge b-blue">💵 {{ $fm2($o['coll_total']) }}</span>
                        @endif
                        @if ($o['ret_count'] > 0)
                            <span class="badge b-red">↩️ {{ $fm2($o['ret_total']) }}</span>
                        @endif
                        @if ($o['photo_count'] > 0)
                            <span class="badge b-purple">📸 {{ $o['photo_count'] }}</span>
                        @endif
                        @if ($o['gift_count'] > 0)
                            <span class="badge b-gold">🎁 {{ $o['gift_qty'] }}</span>
                        @endif
                        @if ($o['goods_count'] > 0)
                            <span class="badge b-orange">📦 {{ $o['goods_count'] }}</span>
                        @endif
                        @if (! $o['any'])
                            <span class="badge b-gray">{{ __('ops.vb_nothing') }}</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($v->lat !== null && $v->lng !== null)
                            <a href="https://www.google.com/maps?q={{ $v->lat }},{{ $v->lng }}"
                               target="_blank" rel="noopener" style="font-weight:800">📍</a>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn sm" type="button"
                                onclick="visitDetail('{{ $v->id }}')">{{ __('ops.vb_details') }}</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('ops.no_visits') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pag">{{ $visits->links('pagination::simple-default') }}</div>
</div>

{{-- ═══════════ مودال التفاصيل — واحد بيتملى من الحمولة ═══════════ --}}
<dialog id="dlgVisit" class="wide">
    <div class="dlg">
        <h4 id="vdTitle">{{ __('ops.vb_details') }}</h4>
        <div id="vdBody"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgVisit')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

<script>
const VISIT_DETAIL = {!! $detailJson !!};
const VD_T = {!! $modalLabels !!};

function vdEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function vdPhotos(list, label) {
    if (!list || !list.length) { return ''; }

    const imgs = list.map(u => '<a href="' + vdEsc(u) + '" target="_blank" rel="noopener">'
        + '<img src="' + vdEsc(u) + '" alt="" loading="lazy" '
        + 'style="width:150px;height:150px;object-fit:cover;border-radius:10px;border:1px solid var(--border)"></a>').join('');

    return '<div style="flex:1;min-width:170px">'
        + '<div style="font-size:11px;font-weight:800;color:var(--muted);margin-bottom:6px">'
        + vdEsc(label) + ' · ' + list.length + '</div>'
        + '<div style="display:flex;gap:6px;flex-wrap:wrap">' + imgs + '</div></div>';
}

function vdLine(icon, label, value) {
    return '<div style="display:flex;gap:8px;padding:5px 0;border-bottom:1px solid var(--border)">'
        + '<span style="width:22px">' + icon + '</span>'
        + '<span style="flex:1;font-size:12px;color:var(--muted)">' + vdEsc(label) + '</span>'
        + '<span style="font-weight:800;font-size:12.5px">' + value + '</span></div>';
}

function visitDetail(id) {
    const d = VISIT_DETAIL[String(id)];
    if (!d) { return; }

    document.getElementById('vdTitle').textContent = d.client + ' — ' + d.rep;

    let h = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;font-size:12px;color:var(--muted)">'
        + '<span>' + vdEsc(d.date || '') + '</span>'
        + (d.zone ? '<span>· ' + vdEsc(d.zone) + '</span>' : '')
        + (d.chain ? '<span>· 🏬 ' + vdEsc(d.chain) + '</span>' : '')
        + '</div>';

    h += vdLine('🟢', VD_T.in, vdEsc(d.in || '—'));
    h += vdLine('🔴', VD_T.out, d.out ? vdEsc(d.out) : vdEsc(VD_T.open));
    h += vdLine('⏱️', VD_T.duration,
        d.minutes === null ? '—' : vdEsc(VD_T.minutes.replace(':n', d.minutes)));

    if (d.invoices && d.invoices.length) {
        const inv = d.invoices.map(i => '<a class="badge b-green" style="text-decoration:none" href="'
            + vdEsc(i.url) + '">🧾 ' + vdEsc(i.number) + ' · ' + vdEsc(i.total) + '</a>').join(' ');
        h += vdLine('🧾', VD_T.invoices, inv);
    }
    if (d.coll_count > 0) {
        h += vdLine('💵', VD_T.collections,
            '<span class="badge b-blue">' + vdEsc(d.coll_total) + '</span>');
    }
    if (d.ret_count > 0) {
        h += vdLine('↩️', VD_T.returns,
            '<span class="badge b-red">' + vdEsc(d.ret_total) + '</span>');
    }
    if (d.gift_qty > 0) {
        h += vdLine('🎁', VD_T.gifts, '<span class="badge b-gold">' + d.gift_qty + '</span>');
    }
    if (d.goods_count > 0) {
        h += vdLine('📦', VD_T.goods, '<span class="badge b-orange">' + d.goods_count + '</span>');
    }
    if (d.note) {
        h += vdLine('📝', VD_T.note, vdEsc(d.note));
    }

    const ph = vdPhotos(d.before, VD_T.before) + vdPhotos(d.after, VD_T.after);

    if (ph) {
        h += '<div style="margin-top:12px"><div style="font-weight:800;font-size:12.5px;margin-bottom:7px">🖼️ '
            + vdEsc(VD_T.photos) + '</div>'
            + '<div style="display:flex;gap:16px;flex-wrap:wrap">' + ph + '</div></div>';
    }

    const links = [];
    if (d.client_url) {
        links.push('<a class="btn sm" href="' + vdEsc(d.client_url) + '">' + vdEsc(VD_T.client_card) + '</a>');
    }
    if (d.map) {
        links.push('<a class="btn sm" target="_blank" rel="noopener" href="' + vdEsc(d.map) + '">📍 '
            + vdEsc(VD_T.map) + '</a>');
    }
    if (links.length) {
        h += '<div style="display:flex;gap:8px;margin-top:12px">' + links.join('') + '</div>';
    }

    document.getElementById('vdBody').innerHTML = h;
    openDlg('dlgVisit');
}
</script>

@endsection
