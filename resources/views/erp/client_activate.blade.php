@extends('layouts.system')

{{--
    تفعيل العملاء المستوردين.

    ⚠️ **الشاشة دي هي البوابة بين الداتا المستوردة والشغل.** كل فرع
    جاي من الشيتات بيوصل هنا موقوف، ومن هنا بس بيبقى شغّال.
--}}

@php
    use App\Support\Governorates;

    $fmt = fn ($n) => number_format((float) $n);
    $f = $filters;
@endphp

@section('title', __('client.activate_clients'))

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.waiting_activation') }}</div>
        <div class="val mid">{{ $fmt($waiting) }}</div>
        <div class="sub2">{{ __('client.waiting_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.live_clients') }}</div>
        <div class="val pos">{{ $fmt($live) }}</div>
    </div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}"
               placeholder="{{ __('client.search_ph') }}" style="flex:1;min-width:200px">

        <select name="group" style="min-width:180px">
            <option value="">— {{ __('nav.chains') }} —</option>
            @foreach ($groups as $g)
                <option value="{{ $g->id }}" @selected((int) ($f['group'] ?? 0) === $g->id)>
                    {{ $g->displayName() }} ({{ $g->off_count }})
                </option>
            @endforeach
        </select>

        <select name="gov" style="min-width:150px">
            <option value="">— {{ __('geo.governorate') }} —</option>
            @foreach (Governorates::KEYS as $k)
                <option value="{{ $k }}" @selected(($f['gov'] ?? '') === $k)>{{ Governorates::label($k) }}</option>
            @endforeach
        </select>

        <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;white-space:nowrap">
            <input type="checkbox" name="incomplete" value="1" @checked($f['incomplete'] ?? false)>
            {{ __('client.incomplete_only') }}
        </label>

        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.clients.activate') }}">{{ __('common.clear') }}</a>
    </form>

    <form method="POST" action="{{ route('erp.clients.activate.do') }}" id="actForm">
        @csrf
        @if ($errors->any())
            <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
                @foreach ($errors->all() as $msg)
                    <div class="errline" style="margin:0">{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        {{-- ⚠️ **القيم دي بتتطبّق على المعلّم عليهم كلهم.** التفعيل
             واحد واحد بـ455 فرع شغل شهر — والفلترة بسلسلة ومحافظة
             بتخلّي الدفعة متجانسة فعلاً. --}}
        <div class="card" style="background:var(--card2);margin-bottom:12px">
            <h3 style="font-size:13px">⚡ {{ __('client.apply_to_selected') }}</h3>
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.zone') }} <b class="req-star">*</b></label>
                    <select name="zone_id" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($zones as $z)
                            <option value="{{ $z->id }}" @selected(old('zone_id') == $z->id)>
                                {{ $z->displayName() }}
                            </option>
                        @endforeach
                    </select>
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">
                        {{ __('client.zone_required_hint') }}
                    </div>
                </div>
                <div>
                    <label class="f">{{ __('ops.rep') }}</label>
                    <select name="rep_id" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($reps as $r)
                            <option value="{{ $r->id }}">{{ $r->displayName() }} · {{ $r->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('client.account_manager') }}</label>
                    <select name="manager_id" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($managers as $m)
                            <option value="{{ $m->id }}">{{ $m->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('client.price_list') }}</label>
                    <select name="price_list" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($lists as $k => $lbl)
                            <option value="{{ $k }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:12px">
                <span style="font-size:12.5px;color:var(--muted)">
                    <b id="selCount">0</b> {{ __('client.selected') }}
                </span>
                <button class="btn gold" type="submit" id="actBtn" disabled>
                    ✅ {{ __('client.activate_selected') }}
                </button>
            </div>
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th style="width:34px">
                        <input type="checkbox" id="allBox" onchange="toggleAll(this)">
                    </th>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('client.client') }}</th>
                    <th>{{ __('nav.chains') }}</th>
                    <th>{{ __('geo.governorate') }}</th>
                    <th>{{ __('client.zone') }}</th>
                    <th>{{ __('common.address') }}</th>
                    <th>{{ __('common.phone') }}</th>
                    <th>{{ __('client.missing') }}</th>
                </tr>
                @forelse ($clients as $c)
                    @php
                        // ⚠️ الناقص بيتعرض صريح — الفرع اللي هيتفعّل
                        // من غير عنوان المندوب مش هيلاقيه، ومن غير
                        // تليفون محدش هيقدر يتصل قبل ما يروح.
                        $miss = [];
                        if (! $c->name_en) $miss[] = __('common.name_en');
                        if (! $c->address && ! $c->location_url) $miss[] = __('common.address');
                        if (! $c->phone) $miss[] = __('common.phone');
                        if (! $c->governorate) $miss[] = __('geo.governorate');
                        if (! $c->zone_id) $miss[] = __('client.zone');
                    @endphp
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $c->id }}"
                                   class="rowBox" onchange="syncCount()"></td>
                        <td class="num"><b>{{ $c->code }}</b></td>
                        <td>
                            <a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->displayName() }}</b></a>
                            @if ($c->name_en && $c->name !== $c->name_en)
                                <div style="font-size:10.5px;color:var(--muted)">{{ $c->name }}</div>
                            @endif
                        </td>
                        <td><span class="badge b-purple">{{ $c->group?->displayName() ?? '—' }}</span></td>
                        <td style="font-size:11.5px">{{ $c->governorateLabel() ?: '—' }}</td>
                        <td style="font-size:11.5px">{{ $c->zone?->displayName() ?? '—' }}</td>
                        <td style="font-size:11px;color:var(--muted);max-width:280px">
                            {{ Str::limit($c->address ?? '', 60) ?: ($c->location_url ? '🗺️' : '—') }}
                        </td>
                        <td class="num" dir="ltr" style="font-size:11.5px">{{ $c->phone ?: '—' }}</td>
                        <td>
                            @if ($miss === [])
                                <span class="badge b-green">{{ __('client.complete') }}</span>
                            @else
                                <span class="badge b-orange" style="font-size:10px">
                                    {{ implode(' · ', $miss) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('client.no_waiting') }}
                    </td></tr>
                @endforelse
            </table>
        </div>
    </form>

    <div class="pag">{{ $clients->links('pagination::simple-default') }}</div>
</div>

@endsection

@section('scripts')
<script>
/**
 * ⚠️ **«علّم على الكل» بتعلّم على الصفحة دي بس.** الجدول مقسّم 50
 * صف؛ لو التعليم شمل اللي مش ظاهر، ضغطة واحدة كانت هتفعّل 455 فرع
 * محدش راجعهم — وهو بالظبط اللي الشاشة دي اتعملت تمنعه.
 */
function toggleAll(box) {
    document.querySelectorAll('.rowBox').forEach(b => { b.checked = box.checked; });
    syncCount();
}

function syncCount() {
    const n = document.querySelectorAll('.rowBox:checked').length;

    document.getElementById('selCount').textContent = n;
    document.getElementById('actBtn').disabled = n === 0;

    const all = document.getElementById('allBox');
    const total = document.querySelectorAll('.rowBox').length;

    if (all) {
        all.checked = n > 0 && n === total;
        all.indeterminate = n > 0 && n < total;
    }
}

syncCount();
</script>
@endsection
