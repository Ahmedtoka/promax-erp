{{--
    ٦ — الأرصدة الافتتاحية (D6): قيد واحد بتاريخ يوم قبل البداية، بيتكتب من
    جديد كل ترحيل. السطور الأوتوماتيك (عملاء/موردين/مخزون/أصول/سلف) من
    المصادر، واليدوي من هنا، والفرق على «فرق الأرصدة الافتتاحية».
--}}
@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('golive.s6_as_of') }}</div><div class="val num">{{ $asOf?->toDateString() ?? '—' }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('gl.debit') }}</div><div class="val num">{{ $fmt($compose['debit'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('gl.credit') }}</div><div class="val num">{{ $fmt($compose['credit'] ?? 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('golive.s6_difference_now') }}</div>
        <div class="val num {{ abs($compose['difference'] ?? 0) >= 0.005 ? 'mid' : 'pos' }}">{{ $fmt($compose['difference'] ?? 0) }}</div>
        <div class="sub2">{{ __('golive.s6_difference_posted') }}: <span class="num">{{ $fmt($difference) }}</span></div></div>
    <div class="kpi"><div class="lbl">{{ __('golive.s6_posted_entry') }}</div>
        <div class="val num">{{ $posted?->number ?? '—' }}</div>
        <div class="sub2 num">{{ $posted?->created_at?->format('Y-m-d H:i') ?? '' }}</div></div>
</div>

@if ($composeError)
    <div class="alert warn"><span>⚠️</span><span>{{ $composeError }}</span></div>
@endif

{{-- ═══ السطور حسب المصدر ═══ --}}
@if ($compose)
<h4 style="margin:10px 0 6px">{{ __('golive.s6_by_source') }}</h4>
<div class="tablewrap">
    <table>
        <tr><th data-nosum style="text-align:start">{{ __('golive.s6_source') }}</th><th>{{ __('gl.debit') }}</th><th>{{ __('gl.credit') }}</th></tr>
        @foreach ($compose['by_source'] as $key => $src)
            <tr><td style="text-align:start">{{ $src['label'] }}</td>
                <td class="num">{{ $fmt($src['debit']) }}</td><td class="num">{{ $fmt($src['credit']) }}</td></tr>
        @endforeach
    </table>
</div>
@endif

{{-- ═══ السطور اليدوية ═══ --}}
<h4 style="margin:14px 0 6px">{{ __('golive.opening_src_manual') }}</h4>
<div class="tablewrap">
    <table>
        <tr>
            <th data-nosum style="text-align:start">{{ __('gl.account') }}</th>
            <th data-nosum>{{ __('gl.cost_center') }}</th>
            <th>{{ __('gl.debit') }}</th><th>{{ __('gl.credit') }}</th>
            <th data-nosum>{{ __('golive.s6_note') }}</th>
            @if ($canAdmin)<th data-nosum></th>@endif
        </tr>
        @forelse ($lines as $l)
            <tr>
                <td style="text-align:start"><span class="num">{{ $l->account?->code }}</span> · {{ $l->account?->displayName() }}</td>
                <td>{{ $l->costCenter?->code ?? '—' }}</td>
                <td class="num">{{ $fmt($l->debit) }}</td><td class="num">{{ $fmt($l->credit) }}</td>
                <td>{{ $l->note }}</td>
                @if ($canAdmin)
                    <td><form method="POST" action="{{ route('gl.golive.opening.lines.delete', $l) }}">@csrf
                        <button class="btn sm red" type="submit">✕</button></form></td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $canAdmin ? 6 : 5 }}" style="color:var(--muted)">{{ __('golive.s6_no_lines') }}</td></tr>
        @endforelse
    </table>
</div>

@if ($canAdmin)
<form method="POST" action="{{ route('gl.golive.opening.lines.add') }}" class="frow" style="margin-top:8px">
    @csrf
    <label class="fl"><span>{{ __('gl.account') }}</span>
        <select name="account_id" required>
            @foreach ($accounts as $a)
                <option value="{{ $a->id }}">{{ $a->code }} · {{ $a->displayName() }}</option>
            @endforeach
        </select></label>
    <label class="fl"><span>{{ __('gl.cost_center') }}</span>
        <select name="cost_center_id">
            <option value="">—</option>
            @foreach ($centers as $cc)<option value="{{ $cc->id }}">{{ $cc->code }}</option>@endforeach
        </select></label>
    <label class="fl"><span>{{ __('golive.s6_side') }}</span>
        <select name="side"><option value="debit">{{ __('gl.debit') }}</option><option value="credit">{{ __('gl.credit') }}</option></select></label>
    <label class="fl"><span>{{ __('gl.amount') }}</span><input type="number" name="amount" step="0.01" min="0.01" required></label>
    <label class="fl"><span>{{ __('golive.s6_note') }}</span><input name="note" maxlength="250"></label>
    <button class="btn gold" type="submit">＋ {{ __('golive.s6_add_line') }}</button>
</form>
@endif

{{-- ═══ العملاء والموردين بالاسم ═══ --}}
<h4 style="margin:16px 0 6px">{{ __('golive.s6_by_name') }}</h4>
<form method="GET" action="{{ route('gl.golive.show', 6) }}" class="searchbar">
    <input name="q" value="{{ $q }}" placeholder="{{ __('golive.s6_search') }}" minlength="2">
    <button class="btn" type="submit">🔎</button>
</form>

@if ($q !== '')
<div class="tablewrap">
    <table>
        <tr><th data-nosum style="text-align:start">{{ __('golive.s6_party') }}</th><th data-nosum>{{ __('golive.s6_party_kind') }}</th>
            @if ($canAdmin)<th data-nosum>{{ __('golive.s6_amount_hint') }}</th>@endif</tr>
        @foreach ($clients as $c)
            <tr><td style="text-align:start"><span class="num">{{ $c->code }}</span> — {{ $c->displayName() }}</td>
                <td><span class="badge b-blue">{{ __('golive.s6_client') }}</span></td>
                @if ($canAdmin)
                    <td><form method="POST" action="{{ route('gl.golive.opening.client') }}" style="display:flex;gap:6px">@csrf
                        <input type="hidden" name="client_id" value="{{ $c->id }}">
                        <input type="number" name="amount" step="0.01" required style="width:130px">
                        <button class="btn sm gold" type="submit">{{ __('common.save') }}</button></form></td>
                @endif
            </tr>
        @endforeach
        @foreach ($suppliers as $s)
            <tr><td style="text-align:start"><span class="num">{{ $s->code }}</span> — {{ $s->displayName() }}</td>
                <td><span class="badge b-purple">{{ __('golive.s6_supplier') }}</span></td>
                @if ($canAdmin)
                    <td><form method="POST" action="{{ route('gl.golive.opening.supplier') }}" style="display:flex;gap:6px">@csrf
                        <input type="hidden" name="supplier_id" value="{{ $s->id }}">
                        <input type="number" name="amount" step="0.01" required style="width:130px">
                        <button class="btn sm gold" type="submit">{{ __('common.save') }}</button></form></td>
                @endif
            </tr>
        @endforeach
        @if ($clients->isEmpty() && $suppliers->isEmpty())
            <tr><td colspan="{{ $canAdmin ? 3 : 2 }}" style="color:var(--muted)">{{ __('golive.s6_no_results') }}</td></tr>
        @endif
    </table>
</div>
@if ($clientsMore || $suppliersMore)
    <p style="font-size:12px;color:var(--muted)">{{ __('golive.s6_more', ['n' => \App\Services\Gl\GoLive\Steps\OpeningStep::SEARCH_LIMIT]) }}</p>
@endif
@endif

@if ($clientOpenings->isNotEmpty() || $supplierOpenings->isNotEmpty())
<h4 style="margin:14px 0 6px">{{ __('golive.s6_saved_by_name') }}</h4>
<div class="tablewrap">
    <table>
        <tr><th data-nosum style="text-align:start">{{ __('golive.s6_party') }}</th><th data-nosum>{{ __('golive.s6_party_kind') }}</th><th>{{ __('gl.debit') }}</th><th>{{ __('gl.credit') }}</th></tr>
        @foreach ($clientOpenings as $t)
            <tr><td style="text-align:start">{{ $t->client?->displayName() }}</td><td><span class="badge b-blue">{{ __('golive.s6_client') }}</span></td>
                <td class="num">{{ $fmt($t->debit) }}</td><td class="num">{{ $fmt($t->credit) }}</td></tr>
        @endforeach
        @foreach ($supplierOpenings as $t)
            <tr><td style="text-align:start">{{ $t->supplier?->displayName() }}</td><td><span class="badge b-purple">{{ __('golive.s6_supplier') }}</span></td>
                <td class="num">{{ $fmt($t->debit) }}</td><td class="num">{{ $fmt($t->credit) }}</td></tr>
        @endforeach
    </table>
</div>
@endif

@if ($canAdmin)
    <form method="POST" action="{{ route('gl.golive.opening.post') }}" style="margin-top:12px">
        @csrf
        <button class="btn gold" type="submit" @disabled($asOf === null)>📌 {{ __('golive.s6_post') }}</button>
        <span style="font-size:12px;color:var(--muted)">{{ __('golive.s6_post_hint') }}</span>
    </form>
@endif
