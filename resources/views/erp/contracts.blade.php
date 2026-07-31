@extends('layouts.system')

@section('title', __('client.contracts_and_coverage'))

@php
    use Illuminate\Support\Str;

    $isRtl = app()->getLocale() === 'ar';

    $fmt = fn ($n) => number_format((float) $n);         // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps(); @endphp

@section('actions')
    @if ($manager)<button class="btn gold" onclick="openDlg('dlgNewC')">+ {{ __('client.new_contract') }}</button>@endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.signed_contracts') }}</div>
        <div class="val pos">{{ $contracts->count() }}</div>
        <div class="sub2">{{ __('client.out_of_clients', ['count' => $clientsCount]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('report.average_discount') }}</div>
        <div class="val">{{ number_format($avgDisc * 100, 1) }}%</div>
        <div class="sub2">{{ __('client.invoice_discount') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.total_deduction') }}</div>
        <div class="val {{ $avgTotalDeduction > 0.25 ? 'neg' : 'mid' }}">{{ number_format($avgTotalDeduction * 100, 1) }}%</div>
        <div class="sub2">{{ __('client.avg_true_deduction') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.annual_commitment') }}</div>
        <div class="val" style="color:var(--primary)">{{ $fmt($totalCommitment) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('client.annual_commitment_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('report.purchases_under_contract') }}</div>
        <div class="val">{{ $fmt($covered) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ number_format($covered / max($totalPurch, 1) * 100, 1) }}% {{ __('report.of_total') }}</div>
    </div>
</div>

{{-- ═══════════ اللي محتاج قرار دلوقتي ═══════════ --}}
@php
    $alerts = [
        ['noticeMissed', $noticeMissed, 'b-red',    '⏰', 'client.alert_notice_missed'],
        ['noticeSoon',   $noticeSoon,   'b-orange', '📅', 'client.alert_notice_soon'],
        ['expiringSoon', $expiringSoon, 'b-orange', '⌛', 'client.alert_expiring'],
        ['unsigned',     $unsigned,     'b-red',    '✍️', 'client.alert_unsigned'],
        ['unlinked',     $unlinked,     'b-gray',   '🔗', 'client.alert_unlinked'],
        ['consignment',  $consignment,  'b-blue',   '📦', 'client.alert_consignment'],
    ];
    $anyAlert = collect($alerts)->contains(fn ($a) => $a[1]->count() > 0);
@endphp

@if ($anyAlert)
<div class="card">
    <h3>🔔 {{ __('client.needs_decision') }}</h3>
    @foreach ($alerts as [$key, $rows, $cls, $icon, $label])
        @if ($rows->count() > 0)
            <div style="margin-bottom:14px">
                <label class="f">
                    <span class="badge {{ $cls }}">{{ $icon }} {{ __($label) }}</span>
                    <span style="color:var(--muted)">({{ $rows->count() }})</span>
                </label>
                <div class="tablewrap">
                    <table>
                        <tr>
                            <th>{{ __('client.contract') }}</th>
                            <th>{{ __('client.chain') }}</th>
                            <th>{{ __('client.ends_at') }}</th>
                            <th class="num">{{ __('client.days_to_expiry') }}</th>
                            <th>{{ __('common.notes') }}</th>
                        </tr>
                        @foreach ($rows as $ct)
                            <tr>
                                <td class="num"><b>{{ $ct->number }}</b></td>
                                <td>
                                    @if ($ct->client)
                                        <a href="{{ route('erp.clients.show', $ct->client) }}">{{ $ct->client->displayName() }}</a>
                                    @else
                                        {{ $ct->displayChain() ?: '—' }}
                                        @if ($ct->group_id)<span class="badge b-gray">{{ __('client.chain') }}</span>@endif
                                    @endif
                                </td>
                                <td class="num">{{ $ct->ends_at?->format('Y-m-d') ?? '—' }}</td>
                                <td class="num {{ $ct->daysLeft() === null ? '' : ($ct->daysLeft() < 0 ? 'neg' : ($ct->daysLeft() <= 90 ? 'mid' : '')) }}">
                                    {{ $ct->daysLeft() === null ? '—' : $fmt($ct->daysLeft()) }}
                                </td>
                                <td style="white-space:normal;max-width:340px;font-size:11.5px;color:var(--muted)">
                                    @if ($key === 'noticeMissed' || $key === 'noticeSoon')
                                        {{ __('client.notice_days_n', ['days' => $ct->notice_days]) }}
                                        — {{ $ct->noticeDeadline()?->format('Y-m-d') }}
                                    @elseif ($key === 'unlinked')
                                        {{ __('client.link_to_client_hint') }}
                                    @elseif ($key === 'consignment')
                                        {{ __('client.settlement_consignment') }}
                                    @elseif ($isRtl)
                                        {{ Str::limit($ct->note, 110) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif
    @endforeach
</div>
@endif

{{-- ═══════════ الخصومات المخفية ═══════════ --}}
@if ($hiddenCost->count() > 0)
<div class="card">
    <h3>🫥 {{ __('client.hidden_deductions') }}
        <span class="side">{{ __('client.hidden_deductions_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.chain') }}</th>
                <th class="num">{{ __('client.invoice_discount') }}</th>
                <th class="num">{{ __('client.after_invoice') }}</th>
                <th class="num">{{ __('client.total_deduction') }}</th>
                <th class="num">{{ __('client.withholding') }}</th>
                <th class="num">{{ __('client.annual_commitment') }}</th>
            </tr>
            @foreach ($hiddenCost as $ct)
                <tr>
                    <td>
                        @if ($ct->client)
                            <a href="{{ route('erp.clients.show', $ct->client) }}">{{ $ct->client->displayName() }}</a>
                        @else
                            {{ $ct->displayChain() ?: '—' }}
                        @endif
                    </td>
                    <td class="num">{{ number_format($ct->discount * 100, 2) }}%</td>
                    <td class="num mid"><b>+{{ number_format($ct->hiddenDeduction() * 100, 2) }}%</b></td>
                    <td class="num {{ $ct->totalDeduction() > 0.3 ? 'neg' : '' }}">
                        <b>{{ number_format($ct->totalDeduction() * 100, 2) }}%</b>
                    </td>
                    <td class="num {{ $ct->withholding_pct > 0 ? 'neg' : '' }}">
                        {{ $ct->withholding_pct > 0 ? number_format($ct->withholding_pct * 100, 2).'%' : '—' }}
                    </td>
                    <td class="num">{{ $fmt($ct->annualCommitment()) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

<div class="card">
    <h3>📜 {{ __('client.signed_contracts') }}</h3>
    <div class="searchbar">
        <input type="text" id="q" placeholder="🔍 {{ __('client.search_by_client_or_chain') }}" oninput="filterCon()">
    </div>
    <div class="tablewrap">
        <table id="conTbl">
            <tr>
                <th>{{ __('client.client') }}</th><th>{{ __('client.chain') }}</th><th>{{ __('client.type') }}</th>
                <th class="num">{{ __('client.invoice_discount') }}</th><th class="num">{{ __('client.total_deduction') }}</th>
                <th class="num">{{ __('client.clause') }}</th>
                <th>{{ __('client.payment_terms') }}</th><th>{{ __('client.expires_on') }}</th>
                <th class="num">{{ __('client.purchases') }}</th><th class="num">{{ __('client.balance') }}</th><th></th>
                @if ($manager)<th></th>@endif
            </tr>
            @foreach ($contracts as $ct)
                {{-- ⚠️ العقد ممكن يكون للسلسلة أو من غير ربط — client ممكن تكون null --}}
                <tr data-txt="{{ $ct->client?->displayName() }} {{ $ct->chain }} {{ $ct->chain_en }} {{ $ct->number }}">
                    <td>
                        @if ($ct->client)
                            <a href="{{ route('erp.clients.show', $ct->client) }}"><b>{{ $ct->client->displayName() }}</b></a>
                        @elseif ($ct->group)
                            <b>{{ $ct->group->displayName() }}</b>
                            <span class="badge b-gray">{{ __('client.chain') }}</span>
                        @else
                            <b>{{ $ct->displayChain() ?: '—' }}</b>
                            <span class="badge b-orange">{{ __('client.alert_unlinked') }}</span>
                        @endif
                        <br><span style="font-size:10px;color:var(--muted)" class="num">{{ $ct->number }}</span>
                    </td>
                    <td>{{ $ct->displayChain() ?: '—' }}</td>
                    <td><span class="badge b-blue">{{ $ct->typeLabel() }}</span></td>
                    <td class="num"><b>{{ number_format($ct->discount * 100, 2) }}%</b></td>
                    <td class="num {{ $ct->totalDeduction() > 0.3 ? 'neg' : '' }}">
                        {{ number_format($ct->totalDeduction() * 100, 2) }}%
                    </td>
                    <td class="num">{{ $ct->contractClauses->count() }}</td>
                    {{-- ⚠️ terms نص حر عربي من العقد. في الإنجليزي بنعرض
                         أيام السداد المنظّمة بدله بدل ما نخلط اللغتين. --}}
                    <td>
                        @if ($isRtl)
                            {{ Str::limit($ct->terms, 40) ?: '—' }}
                        @else
                            {{ $ct->paymentDays() !== null
                                ? __('client.days_countable', ['count' => $ct->paymentDays()])
                                : '—' }}
                        @endif
                    </td>
                    <td class="num">
                        @if ($ct->ends_at)
                            <span class="badge {{ $ct->isExpiring() ? 'b-orange' : 'b-gray' }}">{{ $ct->ends_at->format('Y-m-d') }}</span>
                        @else — @endif
                    </td>
                    <td class="num">{{ $ct->client ? $fmt($ct->client->purchases) : '—' }}</td>
                    <td class="num {{ ($ct->client?->balance ?? 0) > 0 ? 'neg' : 'pos' }}">
                        {{ $ct->client ? $fmt($ct->client->balance) : '—' }}
                    </td>
                    <td class="num" style="white-space:nowrap">
                        <a class="btn sm gold" href="{{ route('erp.contracts.show', $ct) }}">📜</a>
                        @if ($ct->file_path)
                            <a class="btn sm" target="_blank" rel="noopener"
                               href="{{ route('erp.contracts.file', $ct) }}">📄</a>
                        @endif
                    </td>
                    @if ($manager)
                        <td>
                            <form method="POST" action="{{ route('erp.contracts.destroy', $ct) }}" onsubmit="return confirm('{{ __('client.confirm_delete_contract') }}')">
                                @csrf @method('DELETE')
                                <button class="btn sm red" type="submit">{{ __('common.delete') }}</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>🚫 {{ __('report.top_uncontracted', ['count' => 20]) }} <span class="side">{{ __('report.contract_opportunities') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('client.client') }}</th><th>{{ __('client.category') }}</th><th>{{ __('client.zone') }}</th><th>{{ __('client.purchases') }}</th><th>{{ __('client.collected') }}</th><th>{{ __('client.balance') }}</th><th>{{ __('client.collection_rate') }}</th></tr>
            @foreach ($noContract as $c)
                <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                    <td><b>{{ $c->displayName() }}</b></td>
                    <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                    <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $fmt($c->purchases) }}</td>
                    <td class="num pos">{{ $fmt($c->collections) }}</td>
                    <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                    <td class="num">{{ number_format($c->collectionRate() * 100, 1) }}%</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

@if ($manager)
<dialog id="dlgNewC">
    <form class="dlg" method="POST" action="{{ route('erp.contracts.store') }}">
        @csrf
        <h4>{{ __('client.new_contract') }}</h4>
        <div><label class="f">{{ __('client.client') }}</label>
            <select name="client_id" required style="width:100%">
                @foreach ($noContract as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach
            </select>
        </div>
        <div class="frow" style="margin-top:12px">
            <div><label class="f">{{ __('client.chain') }} — {{ __('common.name_ar') }}</label><input type="text" name="chain" style="width:100%"></div>
            {{-- ⚠️ `displayChain()` بترجّع فاضي في الإنجليزي لو `chain_en`
                 فاضي — عن قصد، عشان مايسرّبش عربي لواجهة إنجليزية. يعني
                 من غير الخانة دي اسم السلسلة بيختفي خالص. --}}
            <div><label class="f">{{ __('client.chain') }} — {{ __('common.name_en') }}</label><input type="text" name="chain_en" dir="ltr" maxlength="190" style="width:100%"></div>
            <div><label class="f">{{ __('client.type') }}</label>
                    <select name="type" style="width:100%">
                        @foreach (array_keys(\App\Models\Contract::TYPE_KEYS) as $tk)
                            <option value="{{ $tk }}" @selected(\App\Models\Contract::TYPE_DEFAULT === $tk)>{{ __('client.contract_type_'.$tk) }}</option>
                        @endforeach
                    </select>
                </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.discount_pct') }}</label><input type="number" step="0.5" name="discount" value="15" required style="width:100%"></div>
            <div><label class="f">{{ __('client.payment_terms') }}</label><input type="text" name="terms" value="{{ __('client.terms_default') }}" style="width:100%"></div>
            <div><label class="f">{{ __('client.expires_on') }}</label><input type="date" name="ends_at" style="width:100%"></div>
        </div>
        <div><label class="f">{{ __('common.notes') }}</label><textarea name="note" rows="2" style="width:100%"></textarea></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewC')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
function filterCon() {
    const q = document.getElementById('q').value.trim().toLowerCase();
    document.querySelectorAll('#conTbl tr[data-txt]').forEach(tr => {
        tr.style.display = (!q || tr.dataset.txt.toLowerCase().includes(q)) ? '' : 'none';
    });
}
</script>
@endsection
