@extends('layouts.system')

{{--
    اليومية (١٢/٩/٢٠٢٦) — كل قيد بسطوره ومصدره. القيد الآلي مابيتعدلش
    من هنا: اللي بيتغيّر هو **حساب السطر** بس (زرار ✎) بسجل تدقيق
    كامل، والمبلغ بيتعدّل من المستند نفسه عشان الشجرة تفضل مطابقة
    لكشف حساب العميل.
--}}

@section('title', __('gl.journal'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $originBadge = ['auto' => 'b-gray', 'manual' => 'b-blue', 'reversal' => 'b-red', 'opening' => 'b-purple'];
@endphp

@section('actions')
    @if ($canPost)
        <button class="btn gold" type="button" onclick="glOpenEntry()">＋ {{ __('gl.new_entry') }}</button>
    @endif
@endsection

@section('content')

@if ($errors->has('lines'))
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first('lines') }}</span></div>
@endif
@if ($errors->has('account_id'))
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first('account_id') }}</span></div>
@endif

<div class="card">
    <h3>📒 {{ __('gl.journal') }} <span class="side">{{ __('gl.journal_sub') }}</span></h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div>
            <label class="f">{{ __('gl.origin') }}</label>
            <select name="origin" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                @foreach (\App\Models\Gl\GlEntry::ORIGINS as $o)
                    <option value="{{ $o }}" @selected(request('origin') === $o)>{{ __('gl.origin_'.$o) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('gl.account') }}</label>
            <select name="account" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($filterAccounts as $a)
                    <option value="{{ $a->id }}" @selected(request('account') == $a->id)>{{ $a->code }} · {{ $a->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('common.from') }}</label>
            <input type="date" name="from" value="{{ $range->fromValue() }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('common.to') }}</label>
            <input type="date" name="to" value="{{ $range->toValue() }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('gl.search') }}</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('gl.search_hint') }}">
        </div>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                {{-- ⚠️ data-nosum — رقم القيد والمصدر نصوص مش مبالغ --}}
                <th data-nosum>{{ __('gl.number') }}</th>
                <th>{{ __('common.date') }}</th>
                <th style="text-align:start">{{ __('gl.memo') }}</th>
                <th data-nosum>{{ __('gl.source') }}</th>
                <th style="text-align:start">{{ __('gl.lines') }}</th>
                <th class="num">{{ __('gl.debit') }}</th>
                <th class="num">{{ __('gl.credit') }}</th>
            </tr>
            @forelse ($rows as $e)
                <tr>
                    <td>
                        <b>{{ $e->number }}</b>
                        <div><span class="badge {{ $originBadge[$e->origin] ?? 'b-gray' }}">{{ __('gl.origin_'.$e->origin) }}</span></div>
                        @if ($e->needs_review)
                            <div><span class="badge b-orange">{{ __('gl.needs_review') }}</span></div>
                        @endif
                    </td>
                    <td class="num" style="font-size:11px">{{ $e->date?->format('Y-m-d') }}</td>
                    <td style="text-align:start">
                        {{ $e->memo }}
                        @if ($e->edited_at)
                            <div style="font-size:10.5px;color:var(--muted)">✎ {{ $e->edited_at->format('Y-m-d H:i') }}</div>
                        @endif
                    </td>
                    <td style="font-size:11px">
                        @if (isset($links[$e->id]))
                            <a href="{{ $links[$e->id]['url'] }}">{{ $links[$e->id]['label'] }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td style="text-align:start">
                        @foreach ($e->lines as $l)
                            <div style="display:flex;gap:6px;align-items:center;font-size:11px;padding:1px 0">
                                <span dir="ltr">{{ $l->account?->code }}</span>
                                <span>{{ $l->account?->displayName() }}</span>
                                <span class="num">{{ $fmt($l->debit) }} / {{ $fmt($l->credit) }}</span>
                                @if ($l->overridden)
                                    <span class="badge b-orange">{{ __('gl.override') }}</span>
                                @endif
                                @if ($canPost)
                                    <button class="btn sm" type="button" title="{{ __('gl.override') }}"
                                            onclick="glOverride({{ $l->id }}, {{ (int) $l->account_id }})">✎</button>
                                @endif
                            </div>
                        @endforeach
                    </td>
                    <td class="num"><b>{{ $fmt($e->totalDebit()) }}</b></td>
                    <td class="num"><b>{{ $fmt($e->totalCredit()) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="7" style="color:var(--muted);font-size:12px">{{ __('gl.no_entries') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div style="margin-top:12px">{{ $rows->links() }}</div>
</div>

{{-- ═══════════ قيد يدوي جديد ═══════════ --}}
@if ($canPost)
<dialog id="dlgEntry">
    <form class="dlg" method="POST" action="{{ route('gl.entries.store') }}" id="entryForm">
        @csrf
        <h4>＋ {{ __('gl.new_entry') }}</h4>

        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('gl.manual_hint') }}</span>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.date') }} <b class="req-star">*</b></label>
                <input type="date" name="date" required value="{{ old('date', today()->toDateString()) }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('gl.memo') }} <b class="req-star">*</b></label>
                <input type="text" name="memo" required maxlength="250" value="{{ old('memo') }}" style="width:100%">
            </div>
        </div>

        <div class="tablewrap" style="margin-top:10px">
            <table id="glLines">
                <tr>
                    <th style="text-align:start">{{ __('gl.account') }} <b class="req-star">*</b></th>
                    <th class="num">{{ __('gl.debit') }}</th>
                    <th class="num">{{ __('gl.credit') }}</th>
                    <th></th>
                </tr>
                @for ($i = 0; $i < 2; $i++)
                    <tr class="gl-line">
                        <td style="text-align:start">
                            <select name="lines[{{ $i }}][account_id]" required style="width:100%">
                                <option value="">— {{ __('common.pick') }} —</option>
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}">{{ $a->code }} · {{ $a->displayName() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="number" name="lines[{{ $i }}][debit]" step="0.01" min="0" dir="ltr" oninput="glRecalc()" style="width:100%"></td>
                        <td><input type="number" name="lines[{{ $i }}][credit]" step="0.01" min="0" dir="ltr" oninput="glRecalc()" style="width:100%"></td>
                        <td></td>
                    </tr>
                @endfor
            </table>
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin-top:10px">
            <button class="btn sm" type="button" onclick="glAddLine()">＋ {{ __('gl.add_line') }}</button>
            <span style="margin-inline-start:auto;font-size:12px">
                {{ __('gl.debit') }}: <b id="glSumDr">0.00</b> · {{ __('gl.credit') }}: <b id="glSumCr">0.00</b>
            </span>
            <span id="glBalanced" class="badge b-gray">{{ __('gl.not_balanced') }}</span>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgEntry')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit" id="glSubmit" disabled>{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══════════ تحويل حساب سطر — بسجل تدقيق ═══════════ --}}
<dialog id="dlgOverride">
    <form class="dlg" method="POST" action="{{ route('gl.entries.override', ['line' => '__ID__']) }}" id="ovForm">
        @csrf
        <h4>✎ {{ __('gl.override') }} — <span id="ovFrom" dir="ltr"></span></h4>

        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('gl.override_hint') }}</span>
        </div>

        <div>
            <label class="f">{{ __('gl.account') }} <b class="req-star">*</b></label>
            <select name="account_id" id="ovAccount" required style="width:100%">
                <option value="">— {{ __('common.pick') }} —</option>
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->code }} · {{ $a->displayName() }}</option>
                @endforeach
            </select>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('gl.override_note') }}</label>
            <input type="text" name="note" id="ovNote" maxlength="250" style="width:100%">
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgOverride')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

<script>
const GL_OVERRIDE_URL = @js($canPost ? route('gl.entries.override', ['line' => '__ID__']) : '');
let glLineIndex = 2;

function glOpenEntry() {
    glRecalc();
    openDlg('dlgEntry');
}

// سطر جديد = نسخة من أول سطر بالحسابات كلها، بفهرس جديد وقيم فاضية
function glAddLine() {
    const table = document.getElementById('glLines');
    if (!table) return;
    const first = table.querySelector('tr.gl-line');
    if (!first) return;

    const row = first.cloneNode(true);
    row.querySelectorAll('[name]').forEach(function (el) {
        el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + glLineIndex + ']');
        el.value = '';
    });
    // ⚠️ زرار الشيل بيتحط على النسخ بس — أول سطرين مايتشالوش عشان
    // القيد لازم يفضل سطرين على الأقل (قاعدة السيرفر `min:2`)
    const last = row.querySelector('td:last-child');
    if (last) {
        last.innerHTML = '';
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn sm';
        del.textContent = '✕';
        del.addEventListener('click', function () { row.remove(); glRecalc(); });
        last.appendChild(del);
    }
    table.appendChild(row);
    glLineIndex++;
    glRecalc();
}

// ⚠️ الحفظ مقفول طول ما المدين != الدائن — السيرفر بيرفض القيد غير
// المتوازن برضه (`UnbalancedEntry`)، بس المحاسب بيكون كتب ١٠ سطور
// ورجعوا له بخطأ واحد. القفل بيوريه الفرق وهو بيكتب.
function glRecalc() {
    let dr = 0, cr = 0;
    document.querySelectorAll('#glLines input[name$="[debit]"]').forEach(function (el) { dr += parseFloat(el.value || 0) || 0; });
    document.querySelectorAll('#glLines input[name$="[credit]"]').forEach(function (el) { cr += parseFloat(el.value || 0) || 0; });

    const sumDr = document.getElementById('glSumDr');
    const sumCr = document.getElementById('glSumCr');
    if (sumDr) sumDr.textContent = dr.toFixed(2);
    if (sumCr) sumCr.textContent = cr.toFixed(2);

    const ok = dr > 0 && Math.abs(dr - cr) < 0.005;
    const badge = document.getElementById('glBalanced');
    if (badge) {
        badge.className = 'badge ' + (ok ? 'b-green' : 'b-gray');
        badge.textContent = ok ? @js(__('gl.balanced')) : @js(__('gl.not_balanced'));
    }
    const submit = document.getElementById('glSubmit');
    if (submit) submit.disabled = !ok;
}

function glOverride(lineId, accountId) {
    const form = document.getElementById('ovForm');
    if (!form) return;
    form.action = GL_OVERRIDE_URL.replace('__ID__', lineId);
    const sel = document.getElementById('ovAccount');
    sel.value = accountId || '';
    // اسم الحساب الحالي من الأوبشن نفسه — مش بيتبعت في الـonclick عشان
    // العربي والكوتس مايدخلوش جوه خاصية HTML
    document.getElementById('ovFrom').textContent = sel.selectedIndex > 0 ? sel.options[sel.selectedIndex].text : '';
    document.getElementById('ovNote').value = '';
    openDlg('dlgOverride');
}
</script>

@endsection
