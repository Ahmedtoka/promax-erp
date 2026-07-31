@extends('layouts.system')

@section('title', __('client.contract_page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $pct = fn ($n) => number_format((float) $n * 100, 2).'%';
    $isRtl = app()->getLocale() === 'ar';
    // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps();

    $name = $ct->client?->displayName() ?: $ct->displayChain();
    $days = $ct->daysLeft();
@endphp

@section('actions')
    @if ($ct->file_path)
        <a class="btn gold" target="_blank" rel="noopener"
           href="{{ route('erp.contracts.file', $ct) }}">📄 {{ __('client.view_original') }}</a>
        <a class="btn" download="{{ $ct->number }}.pdf"
           href="{{ route('erp.contracts.file', $ct) }}">⬇️ {{ __('client.download_original') }}</a>
    @endif
    @if ($ct->client)
        <a class="btn" href="{{ route('erp.clients.show', $ct->client) }}">← {{ __('client.back_to_client') }}</a>
    @endif
    <a class="btn" href="{{ route('erp.contracts') }}">{{ __('client.back_to_contracts') }}</a>
    @if (auth()->user()->isManager())
        <button class="btn" onclick="openDlg('dlgClause')">+ {{ __('client.add_clause') }}</button>
    @endif
@endsection

@section('content')

{{-- ═══════════ الرأس ═══════════ --}}
<div class="card">
    <h3>
        📜 {{ $name }}
        <span class="side">
            {{ $ct->number }} · {{ $ct->typeLabel() }}
            @if ($ct->group_id) · {{ __('client.from_chain') }}@endif
        </span>
    </h3>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px">
        <span class="badge {{ $ct->statusClass() }}">{{ $ct->statusLabel() }}</span>
        <span class="badge {{ $ct->isConsignment() ? 'b-orange' : 'b-gray' }}">{{ $ct->settlementLabel() }}</span>
        @if ($ct->auto_renew)<span class="badge b-orange">{{ __('client.auto_renew') }}</span>@endif
        @if (! $ct->signed_ok)<span class="badge b-red">{{ __('client.contract_unsigned') }}</span>@endif
        @if ($branches->count() > 1)
            <span class="badge b-blue">{{ __('client.covered_branches') }}: {{ $branches->count() }}</span>
        @endif
    </div>
</div>

{{-- ═══════════ التنبيهات — أول حاجة تتشاف ═══════════ --}}
@if ($ct->noticeMissed())
    <div class="card"><div class="alert warn">
        ⏰ {{ __('client.notice_missed', ['date' => $ct->noticeDeadline()?->format('Y-m-d')]) }}
    </div></div>
@elseif ($ct->noticeDaysLeft() !== null && $ct->noticeDaysLeft() <= 60)
    <div class="card"><div class="alert warn">
        ⏰ {{ __('client.notice_due', [
            'days' => $ct->noticeDaysLeft(),
            'date' => $ct->noticeDeadline()?->format('Y-m-d'),
        ]) }}
    </div></div>
@endif

@if ($ct->isConsignment())
    <div class="card"><div class="alert info">📦 {{ __('client.consignment_note') }}</div></div>
@endif

{{-- ═══════════ النِسَب الأساسية ═══════════ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.invoice_discount') }}</div>
        <div class="val" style="color:var(--primary)">{{ $pct($ct->discount) }}</div>
        <div class="sub2">{{ __('client.what_reaches_invoice') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.total_deduction') }}</div>
        <div class="val {{ $ct->totalDeduction() > 0.3 ? 'neg' : 'mid' }}">{{ $pct($ct->totalDeduction()) }}</div>
        <div class="sub2">
            @if ($ct->hiddenDeduction() > 0)
                +{{ $pct($ct->hiddenDeduction()) }} {{ __('client.settled_later') }}
            @else
                {{ __('client.all_on_invoice') }}
            @endif
        </div>
    </div>
    @if ($ct->withholding_pct > 0)
        <div class="kpi">
            <div class="lbl">{{ __('client.withholding') }}</div>
            <div class="val neg">{{ $pct($ct->withholding_pct) }}</div>
            <div class="sub2">
                @if ($ct->client)
                    ≈ {{ $fmt($ct->client->withheldAmount()) }} {{ __('common.currency') }}
                @endif
            </div>
        </div>
    @endif
    <div class="kpi">
        <div class="lbl">{{ __('client.annual_commitment') }}</div>
        <div class="val">{{ $fmt($ct->annualCommitment()) }} {{ __('common.currency') }}</div>
        <div class="sub2">
            @if ($ct->monthlyFees() > 0)
                {{ $fmt($ct->monthlyFees()) }} {{ __('client.per_month') }}
            @else
                {{ __('client.annual_commitment_hint') }}
            @endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.days_to_expiry') }}</div>
        <div class="val {{ $days === null ? '' : ($days < 0 ? 'neg' : ($days <= 90 ? 'mid' : 'pos')) }}">
            {{ $days === null ? '—' : $fmt($days) }}
        </div>
        <div class="sub2">{{ $ct->ends_at?->format('Y-m-d') ?? __('client.undated_contract') }}</div>
    </div>
</div>

{{-- ═══════════ الشروط الأساسية ═══════════ --}}
<div class="card">
    <h3>📋 {{ __('client.key_terms') }}</h3>
    <div class="frow">
        <div>
            <label class="f">{{ __('client.payment_days') }}</label>
            <div class="num"><b>{{ $ct->paymentDays() !== null ? __('client.days_countable', ['count' => $ct->paymentDays()]) : '—' }}</b></div>
        </div>
        <div>
            <label class="f">{{ __('client.price_list') }}</label>
            <div>{{ in_array($ct->price_list, \App\Services\Pricing::LISTS, true)
                ? \App\Services\Pricing::listLabel($ct->price_list)
                : __('client.inherit_from_client') }}</div>
        </div>
        <div>
            <label class="f">{{ __('client.starts_at') }}</label>
            <div class="num">{{ $ct->starts_at?->format('Y-m-d') ?? '—' }}</div>
        </div>
        <div>
            <label class="f">{{ __('client.ends_at') }}</label>
            <div class="num">{{ $ct->ends_at?->format('Y-m-d') ?? '—' }}</div>
        </div>
        <div>
            <label class="f">{{ __('client.renewal') }}</label>
            <div>
                {{ $ct->auto_renew ? __('client.auto_renew') : __('client.no_auto_renew') }}
                @if ($ct->notice_days)
                    <br><span style="font-size:10.5px;color:var(--muted)">
                        {{ __('client.notice_days_n', ['days' => $ct->notice_days]) }}
                    </span>
                @endif
            </div>
        </div>
        <div>
            <label class="f">{{ __('client.settlement_mode') }}</label>
            <div>{{ $ct->settlementLabel() }}</div>
        </div>
    </div>
</div>

{{-- ═══════════ النِسَب والفلوس — أهم جدول ═══════════ --}}
@foreach ([
    ['money', $money, '💰', 'client.money_clauses', true],
    ['fees', $fees, '🧾', 'client.fee_clauses', true],
    ['penalties', $penalties, '⚠️', 'client.penalty_clauses', false],
    ['others', $others, '📎', 'client.other_clauses', false],
] as [$key, $rows, $icon, $title, $open])
    @if ($rows->count() > 0)
        <div class="card {{ $open ? '' : 'extra' }}" @if (! $open) style="display:none" @endif>
            <h3>{{ $icon }} {{ __($title) }} <span class="side">{{ $rows->count() }}</span></h3>
            <div class="tablewrap">
                <table>
                    <tr>
                        <th>{{ __('client.clause') }}</th>
                        <th class="num">{{ __('client.clause_value') }}</th>
                        <th>{{ __('client.clause_basis') }}</th>
                        <th>{{ __('client.clause_kind') }}</th>
                        @if ($manager)<th></th>@endif
                    </tr>
                    @foreach ($rows as $cl)
                        <tr>
                            <td style="white-space:normal;max-width:460px">
                                {{ $cl->displayLabel() }}
                                @if ($cl->is_uncertain)
                                    <span class="badge b-orange">{{ __('client.clause_uncertain') }}</span>
                                @endif
                                @if ($cl->is_alternative)
                                    <span class="badge b-gray">{{ __('client.clause_alternative') }}</span>
                                @endif
                                @if ($cl->displayRaw() && $cl->displayRaw() !== $cl->valueLabel())
                                    <br><span style="font-size:10.5px;color:var(--muted)">{{ $cl->displayRaw() }}</span>
                                @endif
                            </td>
                            <td class="num"><b>{{ $cl->valueLabel() }}</b></td>
                            <td><span class="badge b-gray">{{ $cl->basisLabel() }}</span></td>
                            <td><span class="badge {{ $cl->kindClass() }}">{{ $cl->kindLabel() }}</span></td>
                            @if ($manager)
                                <td class="num">
                                    <button class="btn sm" onclick="editClause({{ $cl->id }})">✏️</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif
@endforeach

@if ($penalties->count() + $others->count() > 0)
    <div class="card" style="text-align:center">
        <button class="btn" id="toggleExtra" onclick="toggleExtra()">
            {{ __('client.show_all_clauses', ['count' => $ct->contractClauses->count()]) }}
        </button>
    </div>
@endif

{{-- ═══════════ الفروع المغطاة ═══════════ --}}
@if ($branches->count() > 1)
<div class="card">
    <h3>🏬 {{ __('client.covered_branches') }} <span class="side">{{ $branches->count() }}</span></h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($branches as $b)
            <a class="badge b-gray" style="text-decoration:none"
               href="{{ route('erp.clients.show', $b) }}">{{ $b->displayName() }}</a>
        @endforeach
    </div>
</div>
@endif

{{-- ═══════════ النص الأصلي ═══════════ --}}
{{-- ⚠️ النصوص الحرة محرّرة بالعربي في العقد. في الواجهة الإنجليزية بنوجّه
     لأصل الـ PDF بدل ما نعرض عربي في شاشة إنجليزية. --}}
@if ($isRtl)
    @php
        $texts = array_filter([
            __('client.payment_terms') => $ct->terms,
            __('client.renewal') => $ct->renewal_note,
            __('client.termination') => $ct->termination,
            __('common.notes') => $ct->note,
        ]);
        $textClauses = $ct->clauseList();
    @endphp

    @if ($texts || $textClauses)
    <div class="card">
        <h3>📝 {{ __('client.text_clauses') }}</h3>
        @foreach ($texts as $label => $body)
            <div style="margin-bottom:12px;font-size:12.5px;line-height:1.85">
                <label class="f">{{ $label }}</label>
                {{ $body }}
            </div>
        @endforeach
        @if ($textClauses)
            <ol style="margin-inline-start:20px;font-size:12.5px;line-height:1.95">
                @foreach ($textClauses as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ol>
        @endif
    </div>
    @endif
@else
    <div class="card">
        <div class="alert info">
            📄 {{ __('client.free_text_ar_only') }}
            @if ($ct->file_path)
                <a href="{{ route('erp.contracts.file', $ct) }}" target="_blank" rel="noopener">
                    {{ __('client.view_original') }}
                </a>
            @endif
        </div>
    </div>
@endif

@if ($manager)
{{-- تعديل بند — نفس المودال بيتملى بالجافاسكريبت --}}
<dialog id="dlgClause">
    <form class="dlg" method="POST" id="clauseForm" action="{{ route('erp.clauses.store', $ct) }}">
        @csrf
        <input type="hidden" name="clause_id" id="clauseId" value="">
        <h4 id="clauseTitle">{{ __('client.add_clause') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('client.clause_kind') }}</label>
                <select name="kind" id="clauseKind" required style="width:100%">
                    @foreach (array_keys(\App\Models\ContractClause::KINDS) as $k)
                        <option value="{{ $k }}">{{ __('client.clause_kind_'.$k) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('client.clause_basis') }}</label>
                <select name="basis" id="clauseBasis" required style="width:100%">
                    @foreach (\App\Models\ContractClause::BASES as $b)
                        <option value="{{ $b }}">{{ __('client.clause_basis_'.$b) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('client.clause_pct') }} %</label>
                <input type="number" step="0.01" min="0" max="100" name="pct" id="clausePct" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.clause_amount') }}</label>
                <input type="number" step="0.01" min="0" name="amount" id="clauseAmount" style="width:100%">
            </div>
        </div>

        {{-- ⚠️ النصين مع بعض. `displayLabel()` بترجّع «بند من غير ترجمة»
             لو الإنجليزي فاضي — مابترجعش العربي — فالبند بيختفي من
             صفحة العقد الإنجليزية بدل ما يبان بلغة تانية. --}}
        <div class="frow" style="margin-bottom:12px">
            <div>
                <label class="f">{{ __('client.clause') }} — {{ __('common.name_ar') }}</label>
                <input type="text" name="label" id="clauseLabel" maxlength="400" required style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.clause') }} — {{ __('common.name_en') }}</label>
                <input type="text" name="label_en" id="clauseLabelEn" dir="ltr" maxlength="400" style="width:100%">
            </div>
        </div>
        <div style="margin-bottom:12px">
            <label class="f">{{ __('common.notes') }}</label>
            <input type="text" name="note" id="clauseNote" style="width:100%">
        </div>
        <div style="margin-bottom:12px">
            <label style="font-size:12px">
                <input type="hidden" name="is_uncertain" value="0">
                <input type="checkbox" name="is_uncertain" value="1" id="clauseUncertain">
                {{ __('client.clause_uncertain_hint') }}
            </label>
        </div>

        <div class="alert info" style="margin-bottom:10px">{{ __('client.clause_recalc_hint') }}</div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn red" type="button" id="clauseDelete" style="display:none"
                    onclick="deleteClause()">{{ __('common.delete') }}</button>
            <button class="btn" type="button" onclick="closeDlg('dlgClause')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
@if ($manager)
    @php
        $clauseJson = $ct->contractClauses->mapWithKeys(fn ($cl) => [$cl->id => [
            'kind' => $cl->kind,
            'basis' => $cl->basis,
            'pct' => $cl->pct === null ? null : round((float) $cl->pct * 100, 2),
            'amount' => $cl->amount === null ? null : (float) $cl->amount,
    // ⚠️ **الأعمدة الخام مش `displayLabel()`.** الفورم فيه خانتين
            // منفصلتين دلوقتي، فكل واحدة لازم تشوف عمودها الحقيقي.
            // `displayLabel()` بترجّع نص لغة الواجهة — أو «لسه مش متترجم»
            // اللي كان بيتحفظ كأنه اسم البند.
            'label' => (string) $cl->label,
            'label_en' => (string) $cl->label_en,
            'note' => $cl->displayNote(),
            'uncertain' => (bool) $cl->is_uncertain,
        ]])->all();
    @endphp
    <script type="application/json" id="clauseData">{!! json_encode($clauseJson,
        JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <script>
        const CLAUSE_ADD_TITLE = @js(__('client.add_clause'));
        const CLAUSE_EDIT_TITLE = @js(__('client.edit_clause'));
        const CLAUSE_DELETE_CONFIRM = @js(__('client.delete_clause_confirm'));
        const CLAUSES = JSON.parse(document.getElementById('clauseData').textContent || '{}');

        function editClause(id) {
            const c = CLAUSES[id];
            if (! c) { return; }
            document.getElementById('clauseId').value = id;
            document.getElementById('clauseKind').value = c.kind;
            document.getElementById('clauseBasis').value = c.basis;
            document.getElementById('clausePct').value = c.pct === null ? '' : c.pct;
            document.getElementById('clauseAmount').value = c.amount === null ? '' : c.amount;
            document.getElementById('clauseLabel').value = c.label;
            document.getElementById('clauseLabelEn').value = c.label_en || '';
            document.getElementById('clauseNote').value = c.note || '';
            document.getElementById('clauseUncertain').checked = c.uncertain;
            document.getElementById('clauseDelete').style.display = '';
            document.getElementById('clauseTitle').textContent = CLAUSE_EDIT_TITLE;
            openDlg('dlgClause');
        }

        function deleteClause() {
            if (! confirm(CLAUSE_DELETE_CONFIRM)) { return; }
            const f = document.getElementById('clauseForm');
            const m = document.createElement('input');
            m.type = 'hidden'; m.name = '_method'; m.value = 'DELETE';
            f.appendChild(m);
            f.submit();
        }

        /* الزرار العام بيفتح المودال فاضي */
        const _openDlg = openDlg;
        openDlg = function (id) {
            if (id === 'dlgClause' && document.getElementById('clauseId').value === '') {
                document.getElementById('clauseForm').reset();
                document.getElementById('clauseId').value = '';
                document.getElementById('clauseDelete').style.display = 'none';
                document.getElementById('clauseTitle').textContent = CLAUSE_ADD_TITLE;
            }
            _openDlg(id);
        };

        document.getElementById('dlgClause')?.addEventListener('close', () => {
            document.getElementById('clauseId').value = '';
        });
    </script>
@endif
<script>
    const EXTRA_SHOW = @js(__('client.show_all_clauses', ['count' => $ct->contractClauses->count()]));
    const EXTRA_HIDE = @js(__('client.hide_extra_clauses'));

    function toggleExtra() {
        const cards = document.querySelectorAll('.card.extra');
        const open = cards.length > 0 && cards[0].style.display === 'none';
        cards.forEach(c => { c.style.display = open ? '' : 'none'; });
        document.getElementById('toggleExtra').textContent = open ? EXTRA_HIDE : EXTRA_SHOW;
    }
</script>
@endsection
