@extends('layouts.system')

@section('title', __('field.returns'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $f0 = fn ($n) => number_format((float) $n);
    $periodOn = ! $range->isOpen();
    $exportUrl = fn (string $kind) => request()->fullUrlWithQuery(['export' => $kind, 'page' => null]);
    $units = $sumGood + $sumDamaged;
@endphp

@section('actions')
    <a class="btn green" href="{{ $exportUrl('docs') }}">⬇ {{ __('field.ret_export_docs') }}</a>
    <a class="btn" href="{{ $exportUrl('lines') }}">⬇ {{ __('field.ret_export_lines') }}</a>
@endsection

@section('content')

{{-- ⚠️ الـKPIs والتحليلات والتصدير من **نفس الكويري المفلترة** بتاعة الجدول —
     نطاق واحد. والفترة بتاريخ القيد في كشف الحساب، فالإجمالي هنا هو نفس كارت
     المرتجعات في الصفحة الرئيسية لنفس الفترة. --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">↩️ {{ __('field.ret_kpi_value') }}</div>
        <div class="val neg num">{{ $fmt($sumValue) }}</div>
        <div class="sub2">{{ __('field.ret_kpi_docs', ['count' => $f0($sumDocs)]) }} • {{ __('client.client_countable', ['count' => $sumClients]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📈 {{ __('field.ret_kpi_rate') }}</div>
        <div class="val num {{ $periodSales && $sumValue / max($periodSales, 1) > 0.1 ? 'neg' : 'mid' }}">
            {{ $periodSales ? number_format($sumValue / $periodSales * 100, 1).'%' : '—' }}</div>
        <div class="sub2">{{ $periodSales ? __('field.ret_kpi_rate_hint', ['sales' => $f0($periodSales)]) : __('field.ret_kpi_rate_pick') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">✅ {{ __('field.return_good_units') }}</div>
        <div class="val pos num">{{ $f0($sumGood) }}</div>
        <div class="sub2">{{ __('common.piece') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">⚠️ {{ __('field.return_damaged_units') }}</div>
        <div class="val neg num">{{ $f0($sumDamaged) }}</div>
        <div class="sub2">{{ $units > 0 ? number_format($sumDamaged / $units * 100, 1) : 0 }}% {{ __('field.ret_of_units') }}</div>
    </div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <div style="flex:1;min-width:200px">
            <label class="f">{{ __('common.search') }}</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('field.ret_search_ph') }}" style="width:100%">
        </div>
        <div>
            <label class="f">{{ __('common.from') }}</label>
            <input type="date" name="from" value="{{ $range->fromValue() }}">
        </div>
        <div>
            <label class="f">{{ __('common.to') }}</label>
            <input type="date" name="to" value="{{ $range->toValue() }}">
        </div>
        <div>
            <label class="f">{{ __('nav.chains') }}</label>
            <select name="group">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ $g->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('client.channel') }}</label>
            <select name="channel">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($channels as $ch)
                    <option value="{{ $ch->id }}" @selected((int) ($filters['channel'] ?? 0) === $ch->id)>{{ $ch->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('ops.rep') }}</label>
            <select name="rep">
                <option value="">{{ __('common.all') }}</option>
                <option value="office" @selected(($filters['rep'] ?? '') === 'office')>{{ __('common.office') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected(($filters['rep'] ?? '') === (string) $r->id)>{{ $r->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('field.ret_item') }}</label>
            <select name="product">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" @selected((int) ($filters['product'] ?? 0) === $p->id)>{{ $p->code }} — {{ $p->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('field.return_policy') }}</label>
            <select name="policy">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($policies as $p)
                    <option value="{{ $p }}" @selected(($filters['policy'] ?? '') === $p)>{{ __('field.return_policy_'.$p) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('field.ret_condition') }}</label>
            <select name="condition">
                <option value="">{{ __('common.all') }}</option>
                <option value="good" @selected(($filters['condition'] ?? '') === 'good')>{{ __('field.ret_cond_good') }}</option>
                <option value="damaged" @selected(($filters['condition'] ?? '') === 'damaged')>{{ __('field.ret_cond_damaged') }}</option>
            </select>
        </div>
        @if (! empty($filters['client']))
            <input type="hidden" name="client" value="{{ (int) $filters['client'] }}">
        @endif
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.returns') }}">{{ __('common.clear') }}</a>
        {{-- ⚠️ **الزرار متحرس بنفس مفتاح الراوت.** مدير الفرع عنده
             `!ops.returns.new` — من غير الحارس كان بيشوف الزرار وياخد
             403 أول ما يدوس، وده بالظبط اللي `Access` اتعملت تمنعه. --}}
        @if (\App\Support\Access::allows(auth()->user(), 'ops.returns.new'))
            <a class="btn green" href="{{ route('ops.returns.new') }}">+ {{ __('field.return_doc') }}</a>
        @endif
    </form>

    @if ($periodOn)
        <div class="alert info" style="margin:10px 0">{{ __('field.ret_period_note') }}</div>
    @endif
</div>

{{-- ═══ مين بيرجّع إيه — أعلى 8 في كل تقسيمة، بنفس الفلاتر ═══ --}}
@if ($sumDocs > 0)
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
    <div class="card" style="margin:0">
        <h3>📦 {{ __('field.ret_by_product') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('field.ret_item') }}</th><th class="num">{{ __('field.ret_qty') }}</th><th class="num">{{ __('field.return_damaged_units') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($byProduct as $x)
                <tr class="clickable" onclick="location.href='{{ request()->fullUrlWithQuery(['product' => $x->id, 'page' => null]) }}'">
                    <td>{{ app()->getLocale() === 'en' && $x->name_en ? $x->name_en : $x->name }}</td>
                    <td class="num">{{ $f0($x->q) }}</td>
                    <td class="num {{ $x->dq > 0 ? 'neg' : '' }}">{{ $f0($x->dq) }}</td>
                    <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                </tr>
            @endforeach
        </table></div>
    </div>
    <div class="card" style="margin:0">
        <h3>👥 {{ __('field.ret_by_client') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('client.client') }}</th><th class="num">{{ __('field.ret_docs') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($byClient as $x)
                <tr class="clickable" onclick="location.href='{{ request()->fullUrlWithQuery(['client' => $x->client_id, 'page' => null]) }}'">
                    <td>{{ $clientNames->get($x->client_id)?->fullName() ?? '#'.$x->client_id }}</td>
                    <td class="num">{{ $f0($x->n) }}</td>
                    <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                </tr>
            @endforeach
        </table></div>
    </div>
    <div class="card" style="margin:0">
        <h3>🧑‍💼 {{ __('field.ret_by_rep') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('ops.rep') }}</th><th class="num">{{ __('field.ret_docs') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($byRep as $x)
                <tr class="clickable" onclick="location.href='{{ request()->fullUrlWithQuery(['rep' => $x->user_id ?: 'office', 'page' => null]) }}'">
                    <td>{{ $x->user_id ? ($repNames->get($x->user_id)?->displayName() ?? '#'.$x->user_id) : __('common.office') }}</td>
                    <td class="num">{{ $f0($x->n) }}</td>
                    <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                </tr>
            @endforeach
        </table></div>
    </div>
</div>
@endif

<div class="card">
    <h3>↩️ {{ __('field.returns') }} <span class="side">{{ __('field.ret_kpi_docs', ['count' => $f0($returns->total())]) }}</span></h3>
    <div class="tablewrap" style="max-height:65vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.channel') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('field.return_policy') }}</th>
                <th class="num">{{ __('field.return_good_units') }}</th>
                <th class="num">{{ __('field.return_damaged_units') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th data-nosum>{{ __('common.date') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($returns as $r)
                <tr class="clickable" onclick="location.href='{{ route('ops.returns.show', $r) }}'">
                    <td><b>{{ $r->number }}</b></td>
                    <td>{{ $r->client?->fullName() ?? '—' }}</td>
                    <td>
                        @if ($r->client?->channel)
                            <span class="badge {{ $r->client->channel->badgeClass() }}">{{ $r->client->channel->displayName() }}</span>
                        @else — @endif
                    </td>
                    <td style="color:var(--muted)">{{ $r->rep?->displayName() ?? __('common.office') }}</td>
                    <td><span class="badge b-purple">{{ $r->policyLabel() }}</span></td>
                    <td class="num">{{ number_format($r->good_units) }}</td>
                    <td class="num {{ $r->damaged_units > 0 ? 'neg' : '' }}">{{ number_format($r->damaged_units) }}</td>
                    <td class="num neg"><b>{{ $fmt($r->grand_total) }}</b></td>
                    <td class="num">{{ $r->created_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('common.no_results') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pag">{{ $returns->links('pagination::simple-default') }}</div>
</div>

@endsection
