@extends('layouts.system')

{{--
    موافقات أوامر توريد الكي أكاونت — شاشة الحسابات (2026-08-04):

    القرار بيتاخد على أساس **رصيد الفرع**: عليه كام، والأمر ده هيزوّد
    عليه كام. موافقة ← أمر تجهيز بينزل المخزن فوراً. تعديل ← الكميات
    بتتغير بنفس السعر وبيتبلغ مدير القناة بإيه اللي اتغير. رفض ←
    الأمر بيتلغي وبيتبلغ مدير القناة بالسبب.
--}}

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('title', __('ops.po_approvals'))

@section('content')

<div class="card">
    <h3>🔏 {{ __('ops.po_approvals') }}
        <span class="side">{{ __('ops.po_approvals_hint') }}</span></h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($pending->isEmpty())
        <div class="alert"><span>✅</span><span>{{ __('ops.po_no_pending') }}</span></div>
    @endif

    @foreach ($pending as $po)
        @php $client = $po->client; @endphp
        <div style="border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
                <div>
                    <b style="font-size:14px">{{ $po->number }}</b>
                    <span class="badge b-purple" style="margin-inline-start:6px">{{ $client?->fullName() ?? '—' }}</span>
                    @if ($po->isLate())<span class="badge b-red">⏰ {{ __('ops.po_late') }}</span>@endif
                </div>
                <div style="font-size:12px;color:var(--muted)">
                    {{ __('ops.rep') }}: <b style="color:var(--ink)">{{ $po->courier?->name ?? '—' }}</b>
                    · {{ __('stock.warehouse') }}: {{ $po->warehouse?->displayName() ?? '—' }}
                    · {{ __('ops.due_at') }}: <b style="color:var(--ink)">{{ $po->due_at?->format('Y-m-d H:i') ?? '—' }}</b>
                    · {{ __('ops.by') }}: {{ $po->creator?->name ?? '—' }}
                </div>
            </div>

            {{-- ⚠️ قلب القرار: المبلغ ورصيد الفرع جنب بعض --}}
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin:10px 0;font-size:13px">
                <div>{{ __('ops.po_amount') }}: <b style="color:var(--royal-blue)">{{ $fmt($po->payable()) }} {{ __('common.currency') }}</b></div>
                @if ($client)
                    <div>
                        {{ __('ops.branch_balance') }}:
                        @if ((float) $client->balance > 0)
                            <b style="color:#B00020">{{ __('ops.branch_owes') }} {{ $fmt($client->balance) }}</b>
                        @else
                            <b style="color:#1B7A3D">{{ __('ops.branch_credit') }} {{ $fmt(abs((float) $client->balance)) }}</b>
                        @endif
                    </div>
                    <div style="color:var(--muted)">
                        {{ __('ops.after_po') }}: <b style="color:var(--ink)">{{ $fmt((float) $client->balance + $po->payable()) }}</b>
                    </div>
                @endif
            </div>

            <form method="POST" action="{{ route('ops.po.decide', $po) }}">
                @csrf
                <div class="tablewrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('stock.item') }}</th>
                                <th class="num">{{ __('ops.qty_requested') }}</th>
                                <th class="num" style="width:130px">{{ __('ops.qty_after_edit') }}</th>
                                <th class="num">{{ __('ops.price') }}</th>
                                <th class="num">{{ __('common.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($po->items as $it)
                                <tr>
                                    <td><b>{{ $it->product?->displayName() ?? '—' }}</b></td>
                                    <td class="num">{{ $fmt($it->qty) }}
                                        @if ($bd = $it->product?->packBreakdown((int) $it->qty))
                                            <div style="font-size:10px;color:var(--muted)">{{ $bd }}</div>
                                        @endif
                                    </td>
                                    {{-- التعديل بالقطع — فاضية يعني سيبها زي ما هي، و0 يعني شيل الصنف --}}
                                    <td class="num">
                                        <input type="number" min="0" max="999999" style="width:100%"
                                               name="qty_edit[{{ $it->id }}]" placeholder="{{ $fmt($it->qty) }}">
                                    </td>
                                    <td class="num">{{ number_format((float) $it->price, 2) }}</td>
                                    <td class="num">{{ $fmt($it->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px">
                    {{-- تعديل كامل (أصناف/مندوب/معاد) — مفتوح للحسابات ولصاحب الأمر --}}
                    @if (\App\Support\Access::action(auth()->user(), 'act.ka.edit'))<a class="btn" href="{{ route('ops.po.edit', $po) }}">✏️ {{ __('ops.po_edit') }}</a>@endif
                    <a class="btn" href="{{ route('ops.po.print', $po) }}" target="_blank">🖨️ {{ __('ops.print') }}</a>
                    <input type="text" name="note" maxlength="500" style="flex:1;min-width:220px"
                           placeholder="{{ __('ops.decision_note_ph') }}">
                    {{-- زرارين بنفس الفورم — قيمة decision بتحدد المسار --}}
                    @if (\App\Support\Access::action(auth()->user(), 'act.ka.decide'))<button class="btn gold" name="decision" value="approved"
                            onclick="return confirm(@json(__('ops.po_approve_confirm')))">✅ {{ __('ops.approve_and_prep') }}</button>
                    <button class="btn red" name="decision" value="rejected"
                            onclick="return confirm(@json(__('ops.po_reject_confirm')))">⛔ {{ __('common.reject') }}</button>@endif
                </div>
                <div style="font-size:11px;color:var(--muted);margin-top:6px">{{ __('ops.po_edit_hint') }}</div>
            </form>
        </div>
    @endforeach
</div>

{{-- ═══ آخر القرارات ═══ --}}
<div class="card">
    <h3>🗂️ {{ __('ops.po_decided') }}</h3>
    <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('stock.pick_order') }}</th>
                    <th>{{ __('client.client') }}</th>
                    <th>{{ __('ops.rep') }}</th>
                    <th class="num">{{ __('ops.po_amount') }}</th>
                    <th>{{ __('ops.decision') }}</th>
                    <th>{{ __('ops.by') }}</th>
                    <th>{{ __('common.date') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($decided as $po)
                    <tr>
                        <td><a href="{{ route('ops.po.print', $po) }}" target="_blank"><b>{{ $po->number }}</b></a>@if ($po->was_edited) <span class="badge b-orange" style="font-size:9.5px">{{ __('ops.edited') }}</span>@endif</td>
                        <td>{{ $po->client?->fullName() ?? '—' }}</td>
                        <td>{{ $po->courier?->name ?? '—' }}</td>
                        <td class="num">{{ $fmt($po->payable()) }}</td>
                        <td><span class="badge {{ $po->approvalClass() }}">{{ $po->approvalLabel() }}</span>
                            @if ($po->approval_note)<div style="font-size:10.5px;color:var(--muted)">{{ $po->approval_note }}</div>@endif
                        </td>
                        <td>{{ $po->approvedBy?->name ?? '—' }}</td>
                        <td style="font-size:11.5px;color:var(--muted)">{{ $po->approved_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">—</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
