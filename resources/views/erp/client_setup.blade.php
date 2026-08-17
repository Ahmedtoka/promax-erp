@extends('layouts.system')

{{--
    إعداد السلاسل / العملاء — فورم واحد بكل الصفوف  ·  ١٧ أغسطس ٢٠٢٦

    ⚠️ **فورم واحد كبير** (طلب المالك: «عاوز Save All»). كل خانة
    اسمها `rows[id][field]`:
      • «حفظ الكل» بيبعت كل الصفوف.
      • زرار الصف بيبعت نفس الفورم مع `only=id` — السيرفر بيحفظ
        الصف ده بس.
      • «طبّق على الكل» بيملا كل الصفوف الظاهرة بقيم الشريط العلوي
        **من غير حفظ** — بتشوف اللي هيتكتب وبعدين تدوس حفظ الكل.
--}}

@php
    use App\Support\Divisions;
    $isChains = $mode === 'chains';
    $lists = $lists;
@endphp

@section('title', $isChains ? __('client.setup_chains') : __('client.setup_clients'))

@section('actions')
    @if ($isChains)
        <a class="btn" href="{{ route('erp.setup.clients') }}">👥 {{ __('client.setup_clients') }}</a>
    @else
        <a class="btn" href="{{ route('erp.setup.chains') }}">🔗 {{ __('client.setup_chains') }}</a>
        <a class="btn {{ request()->boolean('unassigned') ? 'gold' : '' }}"
           href="{{ route('erp.setup.clients', request()->boolean('unassigned') ? [] : ['unassigned' => 1]) }}">
            ⚠️ {{ __('client.no_division') }}</a>
    @endif
@endsection

@section('content')

<form method="POST" id="setupForm"
      action="{{ $isChains ? route('erp.setup.chains.save') : route('erp.setup.clients.save') }}">
@csrf

<div class="card">
    <h3>{{ $isChains ? '🔗 '.__('client.setup_chains') : '👥 '.__('client.setup_clients') }}
        <span class="side">{{ $rows->count() }}</span></h3>

    {{-- ═══ شريط «طبّق على الكل» ═══ --}}
    <div style="border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:12px;
                background:var(--card2);display:flex;gap:8px;flex-wrap:wrap;align-items:end">
        <div style="min-width:160px;flex:1">
            <label class="f">{{ __('client.division') }}</label>
            <select id="aaDiv" style="width:100%">
                <option value="">—</option>
                @foreach (Divisions::options() as $k => $lbl)
                    <option value="{{ $k }}">{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width:140px;flex:1">
            <label class="f">{{ __('client.ff_type') }}</label>
            <select id="aaFf" style="width:100%">
                <option value="">— {{ __('client.ff_by_division') }} —</option>
                @foreach (['cashvan', 'delivery', 'online'] as $f)
                    <option value="{{ $f }}">{{ __('client.ff_'.$f) }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width:140px;flex:1">
            <label class="f">{{ __('client.price_list') }}</label>
            <select id="aaPl" style="width:100%">
                <option value="">—</option>
                @foreach ($lists as $pl)
                    <option value="{{ $pl->id }}">{{ $pl->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div style="width:90px">
            <label class="f">{{ __('client.discount') }} %</label>
            <input type="number" id="aaDisc" step="0.01" min="0" max="100" dir="ltr"
                   style="width:100%;text-align:center">
        </div>
        <label style="display:flex;gap:5px;align-items:center;font-size:12px;padding-bottom:8px">
            <input type="checkbox" id="aaInc"> {{ __('client.tax_inclusive') }}
        </label>
        {{-- ⚠️ بيملا بس — مش بيحفظ. الحفظ قرار منفصل بعد ما تشوف --}}
        <button class="btn" type="button" onclick="applyAll()">⤵ {{ __('client.apply_to_all') }}</button>
        <button class="btn gold" type="submit">💾 {{ __('client.save_all') }}</button>
    </div>

    <div class="tablewrap">
        <table data-page="50">
            <thead>
            <tr>
                <th>{{ $isChains ? __('client.chain') : __('client.client') }}</th>
                <th style="min-width:170px" data-nosum>{{ __('client.division') }}</th>
                <th style="min-width:150px" data-nosum>{{ __('client.ff_type') }}</th>
                <th style="min-width:150px" data-nosum>{{ __('client.price_list') }}</th>
                <th style="width:90px" data-nosum>{{ __('client.discount') }} %</th>
                <th style="width:80px" data-nosum>{{ __('client.tax_inclusive') }}</th>
                <th style="width:70px"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    // صف السلسلة بيتعبى من أول فرع كتمثيل — الحفظ بيوحّد
                    $c = $isChains ? $r->clients()->first() : $r;
                    $rid = $r->id;
                @endphp
                <tr>
                    <td>
                        @if ($isChains)
                            <a href="{{ route('erp.groups.show', $r) }}"><b>{{ $r->displayName() }}</b></a>
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ __('client.branch_countable', ['count' => $r->clients_count]) }}</span>
                        @else
                            <a href="{{ route('erp.clients.show', $r) }}"><b>{{ $r->displayName() }}</b></a>
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ $r->group?->displayName() ?? ($r->zone?->displayName() ?? '—') }}</span>
                        @endif
                    </td>
                    <td>
                        <select name="rows[{{ $rid }}][division]" class="su-div" style="width:100%">
                            <option value="">— {{ __('client.no_division') }} —</option>
                            @foreach (Divisions::options() as $k => $lbl)
                                <option value="{{ $k }}" @selected(($c?->division) === $k)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <select name="rows[{{ $rid }}][fulfillment_mode]" class="su-ff" style="width:100%">
                            <option value="">— {{ __('client.ff_by_division') }} —</option>
                            @foreach (['cashvan', 'delivery', 'online'] as $f)
                                <option value="{{ $f }}" @selected(($c?->fulfillment_mode) === $f)>
                                    {{ __('client.ff_'.$f) }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <select name="rows[{{ $rid }}][price_list_id]" class="su-pl" style="width:100%">
                            <option value="">—</option>
                            @foreach ($lists as $pl)
                                <option value="{{ $pl->id }}" @selected(($c?->price_list_id) === $pl->id)>
                                    {{ $pl->displayName() }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input type="number" name="rows[{{ $rid }}][discount]" class="su-disc"
                               step="0.01" min="0" max="100" dir="ltr"
                               style="width:100%;text-align:center"
                               value="{{ $c && (float) $c->discount > 0 ? rtrim(rtrim(number_format((float) $c->discount * 100, 2, '.', ''), '0'), '.') : '' }}">
                    </td>
                    <td style="text-align:center">
                        <input type="hidden" name="rows[{{ $rid }}][inclusive]" value="0">
                        <input type="checkbox" name="rows[{{ $rid }}][inclusive]" value="1"
                               class="su-inc" @checked($c && ! $c->taxable)>
                    </td>
                    <td>
                        {{-- زرار الصف — نفس الفورم مع only=id --}}
                        <button class="btn sm" type="submit" name="only" value="{{ $rid }}">💾</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">
                    {{ __('client.all_assigned') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:12px">
        <button class="btn gold" type="submit">💾 {{ __('client.save_all') }}</button>
    </div>
</div>

</form>

@endsection

@section('scripts')
<script>
/**
 * ⚠️ **بيملا كل الصفوف — بما فيها اللي في صفحات الباجينيشن المخفية**
 * (`querySelectorAll` بيشوف الـDOM كله مش الظاهر بس). ده مقصود:
 * «طبّق على الكل» يعني الكل، مش صفحة الـ50 الحالية.
 *
 * الخانة الفاضية في الشريط **مش بتلمس** الخانة المقابلة في الصفوف —
 * تقدر تطبّق الديفيجن بس وتسيب الخصومات زي ما هي.
 */
function applyAll() {
    const div = document.getElementById('aaDiv').value;
    const ff = document.getElementById('aaFf').value;
    const pl = document.getElementById('aaPl').value;
    const disc = document.getElementById('aaDisc').value;
    const inc = document.getElementById('aaInc').checked;

    if (div) document.querySelectorAll('.su-div').forEach(s => { s.value = div; });
    if (ff) document.querySelectorAll('.su-ff').forEach(s => { s.value = ff; });
    if (pl) document.querySelectorAll('.su-pl').forEach(s => { s.value = pl; });
    if (disc !== '') document.querySelectorAll('.su-disc').forEach(i => { i.value = disc; });
    document.querySelectorAll('.su-inc').forEach(c => { c.checked = inc; });
}
</script>
@endsection
