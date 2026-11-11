@extends('layouts.system')

{{--
    قوايم الأسعار — النظرة العامة (تطوير ١١/١١/٢٠٢٦، طلب المالك):
    «اعرف مين العملاء والسلاسل اللي واخدين القايمة، كام عميل وسلسلة في كل
    قايمة، وعدد المنتجات في كل قايمة» + الشغّالة بس افتراضياً.

    ⚠️ الأرقام كلها من `PriceListOverview::forLists` — نفس الحساب اللي
    صفحة القايمة الواحدة بتعرضه، فالرقمين مايختلفوش أبداً.
--}}

@php
    use App\Services\PriceListOverview as O;
    $fmt = fn ($n) => number_format((float) $n);
    $u = auth()->user();
    $canEdit = $u->isAdmin() || $u->role === 'manager';
    $f = $filters;
    $thSort = function ($key, $label, $numericDefault = true) use ($sort, $dir) {
        $active = $sort === $key;
        $nextDir = $active ? ($dir === 'desc' ? 'asc' : 'desc') : ($numericDefault ? 'desc' : 'asc');
        $url = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => null]);
        $arrow = $active ? ($dir === 'desc' ? ' ▼' : ' ▲') : '';

        return '<a href="'.e($url).'" style="color:'.($active ? '#FFF927' : 'inherit').';text-decoration:none;white-space:nowrap">'
            .e($label).$arrow.'</a>';
    };
    $basisBadge = [
        'consumer_new' => 'b-purple', 'consumer_old' => 'b-purple',
        'copy_new' => 'b-blue', 'copy_old' => 'b-blue', 'net' => 'b-green', 'empty' => 'b-gray',
    ];
    $pct = fn ($d) => $d.'%';
@endphp

@section('title', __('price.price_lists'))

@section('actions')
    @if ($canEdit)
        @if (\App\Support\Access::action(auth()->user(), 'act.prices.edit'))<button class="btn gold" onclick="openDlg('dlgNewList')">+ {{ __('price.new_list') }}</button>@endif
    @endif
@endsection

@section('content')

{{-- ⚠️ **التحذير ده لازم يفضل ظاهر.** السعر اللي بيتكتب هنا هو اللي
     بيتحاسب بيه العميل في كل فاتورة — مش إعداد، ده فلوس. --}}
<div class="alert info" style="margin-bottom:14px">
    <span>🏷️</span><span>{{ __('price.lists_hint') }} {{ __('price.ov_excl_hint') }}</span>
</div>

{{-- الكروت على كل القوايم — كل كارت فلتر بضغطة --}}
<div class="kpis">
    <a @class(['kpi', 'on' => $f['status'] === 'active']) href="{{ route('erp.prices') }}">
        <div class="lbl">✅ {{ __('price.ov_active_lists') }}</div>
        <div class="val">{{ $fmt($counts['active']) }}</div>
        <div class="sub2">{{ __('price.ov_active_sub', ['n' => $totalProducts]) }}</div>
    </a>
    <a @class(['kpi', 'on' => $f['status'] === 'inactive']) href="{{ route('erp.prices', ['status' => 'inactive']) }}">
        <div class="lbl">⏸️ {{ __('price.ov_inactive_lists') }}</div>
        <div class="val" style="color:var(--muted)">{{ $fmt($counts['inactive']) }}</div>
        <div class="sub2">{{ __('price.ov_inactive_sub') }}</div>
    </a>
    <div class="kpi">
        <div class="lbl">👥 {{ __('price.ov_clients_on') }}</div>
        <div class="val">{{ $fmt($counts['clients']) }}</div>
        <div class="sub2">{{ __('price.ov_clients_sub') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🏬 {{ __('price.ov_chains_on') }}</div>
        <div class="val">{{ $fmt($counts['chains']) }}</div>
        <div class="sub2">{{ __('price.ov_chains_sub') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">⚠️ {{ __('price.ov_unlisted') }}</div>
        <div class="val {{ $counts['unlisted'] > 0 ? 'neg' : 'pos' }}">{{ $fmt($counts['unlisted']) }}</div>
        <div class="sub2">{{ __('price.ov_unlisted_sub') }}</div>
    </div>
</div>

<div class="card">
    <h3>{{ __('price.price_lists') }}
        <span class="side">{{ __('price.ov_showing', ['n' => $lists->total(), 'all' => $allLists->count()]) }}</span></h3>

    <form class="searchbar" method="GET" style="margin-bottom:12px">
        <label class="fl grow wide"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $f['q'] }}" placeholder="🔍 {{ __('price.ov_search_ph') }}"></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
            <select name="status">
                <option value="active" @selected($f['status'] === 'active')>{{ __('price.st_active') }}</option>
                <option value="inactive" @selected($f['status'] === 'inactive')>{{ __('price.st_inactive') }}</option>
                <option value="all" @selected($f['status'] === 'all')>{{ __('price.st_all') }}</option>
            </select></label>
        <label class="fl"><span>{{ __('price.col_basis') }}</span>
            <select name="basis">
                <option value="">{{ __('price.st_all') }}</option>
                @foreach (array_keys($basisBadge) as $k)
                    <option value="{{ $k }}" @selected($f['basis'] === $k)>{{ __('price.basis_'.$k) }}</option>
                @endforeach
            </select></label>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.prices') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        {{-- data-plain: الجدول ليه بحث/ترتيب/ترقيم من السيرفر، وصفوف «مين عليها» المتداخلة بتتكسر مع أدوات الجدول العامة --}}
        <table class="pl-table" data-plain>
            <thead>
            <tr>
                <th>{!! $thSort('name', __('price.list'), false) !!}</th>
                <th class="num" data-nosum>{!! $thSort('priced', __('price.col_products')) !!}</th>
                <th data-nosum>{!! $thSort('ratio', __('price.col_basis')) !!}</th>
                <th class="num">{!! $thSort('clients', __('price.col_clients')) !!}</th>
                <th class="num">{!! $thSort('chains', __('price.col_chains')) !!}</th>
                <th data-nosum>{{ __('price.col_discounts') }}</th>
                <th data-nosum>{!! $thSort('last_invoice', __('price.col_last_use')) !!}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($lists as $l)
                @php
                    $s = $stats[$l->id];
                    $b = $s['basis'];
                    $nWho = $s['clients']->count() + $s['chains']->count();
                @endphp
                <tr @class(['pl-off' => ! $l->active])>
                    <td style="min-width:170px">
                        <a href="{{ route('erp.prices.show', $l) }}"><b>{{ $l->displayName() }}</b></a>
                        @if ($l->is_default)
                            <span class="badge b-purple">{{ __('price.default') }}</span>
                        @endif
                        @unless ($l->active)
                            <span class="badge b-gray">{{ __('price.inactive') }}</span>
                        @endunless
                        <div style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $l->code }}</div>
                        @if ($l->notes)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $l->notes }}</div>
                        @endif
                    </td>
                    <td class="num">
                        <b>{{ $fmt($s['priced']) }}</b>
                        @if ($s['missing'] > 0)
                            <div style="font-size:10.5px" class="neg">{{ __('price.ov_missing_n', ['n' => $s['missing']]) }}</div>
                        @else
                            <span class="pos" title="{{ __('price.ov_complete') }}">✓</span>
                        @endif
                    </td>
                    <td style="min-width:150px;max-width:200px">
                        <span class="badge {{ $basisBadge[$b['kind']] }}">{{ __('price.basis_'.$b['kind']) }}</span>
                        @if ($b['differ'] > 0)
                            <div style="font-size:10.5px;color:var(--muted);margin-top:2px">{{ __('price.ratio_of_new', ['n' => $b['differ'], 'pct' => number_format($b['ratio'] * 100, 1)]) }}</div>
                        @endif
                        @if ($b['incl_suspect'])
                            <div><span class="badge b-red" title="{{ __('price.incl_suspect_hint') }}">⚠️ {{ __('price.incl_suspect') }}</span></div>
                        @endif
                    </td>
                    <td class="num">
                        <b>{{ $fmt($s['clients_active']) }}</b>
                        @if ($s['clients_other'] > 0)
                            <div style="font-size:10.5px;color:var(--muted)">{{ __('price.ov_other_status', ['n' => $s['clients_other']]) }}</div>
                        @endif
                        @if ($s['contracts'] > 0)
                            <div style="font-size:10.5px;color:var(--muted)">{{ __('price.ov_contracts_n', ['n' => $s['contracts']]) }}</div>
                        @endif
                    </td>
                    <td class="num"><b>{{ $fmt($s['chains']->count()) }}</b></td>
                    <td style="min-width:150px;max-width:210px;white-space:normal"><div class="pl-chips" style="margin:0">
                        @forelse ($s['discounts']->take(4) as $d => $n)
                            <span class="pl-chip" title="{{ __('price.ov_disc_title', ['n' => $n, 'd' => $d]) }}"><b dir="ltr">{{ $pct($d) }}</b> × {{ $n }}</span>
                        @empty
                            <span class="muted">—</span>
                        @endforelse
                        @if ($s['discounts']->count() > 4)
                            <span class="muted" style="font-size:10.5px" title="{{ $s['discounts']->skip(4)->map(fn ($n, $d) => $d.'% × '.$n)->implode(' · ') }}">+{{ $s['discounts']->count() - 4 }}</span>
                        @endif
                    </div></td>
                    <td style="font-size:11.5px;white-space:nowrap">
                        @if ($inv = $s['last_invoice'])
                            <div>🧾 <a href="{{ route('ops.invoice', $inv->id) }}" dir="ltr">{{ $inv->number }}</a> <span class="muted">{{ $inv->created_at?->format('d/m') }}</span></div>
                        @endif
                        @if ($po = $s['last_po'])
                            <div>📦 <a href="{{ route('ops.pos.show', $po->id) }}" dir="ltr">{{ $po->number }}</a> <span class="muted">{{ $po->created_at?->format('d/m') }}</span></div>
                        @endif
                        @if (! $s['last_invoice'] && ! $s['last_po'])
                            <span class="muted">{{ __('price.never_used') }}</span>
                        @endif
                    </td>
                    <td class="num" style="white-space:nowrap">
                        @if ($nWho > 0)
                            <button type="button" class="btn sm" aria-expanded="false" aria-controls="who-{{ $l->id }}"
                                    onclick="toggleWho(this, 'who-{{ $l->id }}')">👥 {{ __('price.who_on') }} ▾</button>
                        @endif
                        <a class="btn sm" href="{{ route('erp.prices.show', $l) }}">{{ __('price.open_list') }} ←</a>

                        @if ($canEdit)
                            @if (! $l->active)
                                {{-- ⚠️ الزرار بيبان دايماً وبيرفض بالرسالة لو
                                     القايمة ناقصة — إخفاؤه بيخلّي اللي قدامه
                                     يفتكر إن التفعيل مش موجود أصلاً. --}}
                                <form method="POST" action="{{ route('erp.prices.activate', $l) }}" style="display:inline">
                                    @csrf
                                    <button class="btn sm gold" type="submit">✅ {{ __('price.activate') }}</button>
                                </form>
                            @else
                                @unless ($l->is_default)
                                    <form method="POST" action="{{ route('erp.prices.default', $l) }}" style="display:inline">
                                        @csrf
                                        <button class="btn sm" type="submit">{{ __('price.make_default') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('erp.prices.deactivate', $l) }}" style="display:inline">
                                        @csrf
                                        <button class="btn sm" type="submit">{{ __('price.stop') }}</button>
                                    </form>
                                @endunless
                            @endif
                        @endif
                    </td>
                </tr>
                @if ($nWho > 0)
                    {{-- مين على القايمة — بيتفتح من زرار «مين عليها» --}}
                    <tr class="pl-who" id="who-{{ $l->id }}" hidden>
                        <td colspan="8">
                            @if ($s['chains']->isNotEmpty())
                                <div class="pl-who-h">🏬 {{ __('price.ov_chains_n', ['n' => $s['chains']->count()]) }}</div>
                                <div class="pl-chips">
                                    @foreach ($s['chains'] as $g)
                                        <a class="pl-chip" href="{{ route('erp.groups.show', $g->id) }}">{{ $g->displayName() }}
                                            <span class="muted">· {{ __('price.ov_branches_n', ['n' => $g->branches]) }}</span></a>
                                    @endforeach
                                </div>
                            @endif
                            @if ($s['clients']->isNotEmpty())
                                <div class="pl-who-h">👥 {{ __('price.ov_clients_n', ['n' => $s['clients']->count()]) }}</div>
                                <div class="pl-chips">
                                    @foreach ($s['clients']->take(80) as $c)
                                        <a @class(['pl-chip', 'pl-off' => $c->status !== 'active']) href="{{ route('erp.clients.show', $c->id) }}"
                                           title="{{ $c->code }}{{ $c->group ? ' · '.$c->group->displayName() : '' }}">{{ $c->fullName() }}
                                            @if ((float) $c->discount > 0)<b dir="ltr" class="muted">{{ rtrim(rtrim(number_format($c->discount * 100, 2), '0'), '.') }}%</b>@endif</a>
                                    @endforeach
                                    @if ($s['clients']->count() > 80)
                                        <a class="pl-chip" href="{{ route('erp.prices.show', $l) }}#who">{{ __('price.ov_more_n', ['n' => $s['clients']->count() - 80]) }}</a>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ $f['q'] !== '' || $f['basis'] !== '' ? __('price.ov_no_match') : __('price.no_lists') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('partials._pagination', ['p' => $lists])
</div>

@if ($canEdit)
<dialog id="dlgNewList">
    <form class="dlg" method="POST" action="{{ route('erp.prices.store') }}">
        @csrf
        <h4>🏷️ {{ __('price.new_list') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.code') }} <b class="req-star">*</b></label>
                {{-- ⚠️ الكود بيتخزن على العميل والعقد، فتغييره بعد كده
                     بيقطع الربط في صمت والعميل ياخد الافتراضية. --}}
                <input type="text" name="code" dir="ltr" maxlength="30" required style="width:100%"
                       class="{{ $errors->has('code') ? 'bad' : '' }}"
                       value="{{ old('code') }}" placeholder="list-2">
                @error('code')<div class="errline">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="f">{{ __('price.copy_from') }}</label>
                <select name="copy_from" style="width:100%">
                    <option value="">— {{ __('price.start_empty') }} —</option>
                    @foreach ($allLists as $l)
                        <option value="{{ $l->id }}">{{ $l->displayName() }}{{ $l->active ? '' : ' ('.__('price.draft').')' }}</option>
                    @endforeach
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">
                    {{ __('price.copy_hint') }}
                </div>
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="190" style="width:100%"
                       value="{{ old('name_en') }}" placeholder="{{ __('price.name_en_ph') }}">
            </div>
            <div>
                <label class="f">{{ __('price.name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" maxlength="190" required style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}"
                       value="{{ old('name') }}" placeholder="{{ __('price.name_ar_ph') }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('common.notes') }}</label>
            <input type="text" name="notes" maxlength="190" style="width:100%" value="{{ old('notes') }}">
        </div>

        <div class="alert warn" style="margin-top:12px">
            <span>⚠️</span><span>{{ __('price.new_list_note') }}</span>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewList')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<style>
.pl-table tr.pl-off td{color:var(--muted)}
.pl-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border:1px solid var(--border);border-radius:20px;font-size:11px;background:var(--card);white-space:nowrap;margin:2px 0 2px 3px;text-decoration:none;color:inherit}
a.pl-chip:hover{border-color:var(--royal-blue);color:var(--royal-blue)}
.pl-chip.pl-off{opacity:.55;border-style:dashed}
.pl-who td{background:var(--bg,#F6F8FC);padding:10px 14px}
.pl-who td>*{position:sticky;inset-inline-start:14px;max-width:min(1050px,calc(100vw - 320px))}
.pl-who-h{font-size:11.5px;font-weight:800;color:var(--muted);margin:4px 0}
.pl-chips{display:flex;flex-wrap:wrap;gap:2px;margin-bottom:6px}
</style>
<script>
/** «مين عليها» — بيفتح/يقفل صف العملاء والسلاسل تحت القايمة */
function toggleWho(btn, id) {
    const row = document.getElementById(id);
    row.hidden = ! row.hidden;
    btn.setAttribute('aria-expanded', String(! row.hidden));
    btn.textContent = btn.textContent.replace(/[▾▴]$/, row.hidden ? '▾' : '▴');
}
</script>
@endsection
