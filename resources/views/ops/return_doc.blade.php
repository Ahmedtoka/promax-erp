@extends('layouts.system')

@section('title', $r->number)

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('actions')
    <a class="btn" href="{{ route('ops.returns') }}">← {{ __('field.returns') }}</a>
    @if (auth()->user()?->role === 'admin')
        {{-- مرتجع غلط بالكامل؟ — مسح بيعكس القيود ويسحب البضاعة من العهدة --}}
        <form method="POST" action="{{ route('ops.returns.destroy', $r) }}" style="display:inline"
              onsubmit="return confirm(@js(__('ops.del_ret_confirm', ['number' => $r->number])))">
            @csrf
            @method('DELETE')
            <button class="btn" type="submit" style="color:var(--red);border-color:var(--red)">🗑 {{ __('ops.del_return') }}</button>
        </form>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📥 {{ $r->number }}
        <span class="side">{{ $r->created_at->format('Y-m-d h:i A') }}</span></h3>

    <div class="frow">
        <div class="f"><span>{{ __('client.client') }}</span>
            @if ($r->client)<a href="{{ route('erp.clients.show', $r->client) }}"><b>{{ $r->client->fullName() }}</b></a>@else <b>—</b> @endif</div>
        <div class="f"><span>{{ __('ops.rep') }}</span>
            @if ($r->rep)<a href="{{ route('ops.rep', $r->rep) }}"><b>{{ $r->rep->displayName() }}</b></a>@else <b>{{ __('common.office') }}</b> @endif</div>
        <div class="f"><span>{{ __('field.return_policy') }}</span>
            <span class="badge b-purple">{{ $r->policyLabel() }}</span></div>
    </div>

    <div class="kpis">
        <div class="kpi" data-explain onclick="document.getElementById('retItems').scrollIntoView({behavior:'smooth'})"><div class="lbl">{{ __('field.return_good_units') }}</div>
            <div class="val pos">{{ number_format($r->good_units) }}</div></div>
        <div class="kpi" data-explain onclick="document.getElementById('retItems').scrollIntoView({behavior:'smooth'})"><div class="lbl">{{ __('field.return_damaged_units') }}</div>
            <div class="val {{ $r->damaged_units > 0 ? 'neg' : '' }}">{{ number_format($r->damaged_units) }}</div></div>
        <div class="kpi" data-explain onclick="document.getElementById('retItems').scrollIntoView({behavior:'smooth'})"><div class="lbl">{{ __('common.subtotal') }}</div>
            <div class="val">{{ $fmt($r->subtotal) }}</div></div>
        <div class="kpi" data-explain onclick="document.getElementById('retItems').scrollIntoView({behavior:'smooth'})"><div class="lbl">{{ __('common.discount') }}</div>
            <div class="val mid">{{ $fmt($r->discount) }}</div></div>
        <div class="kpi" data-explain onclick="document.getElementById('retItems').scrollIntoView({behavior:'smooth'})"><div class="lbl">{{ __('tax.tax') }}</div>
            <div class="val">{{ $fmt($r->tax_total) }}</div></div>
        {{-- ⚠️ **ده الرقم اللي اتقيّد في الليدجر** — شامل الضريبة،
             زي `grand_total` بتاع الفاتورة بالظبط. --}}
        <div class="kpi" data-explain onclick="document.getElementById('retItems').scrollIntoView({behavior:'smooth'})"><div class="lbl">{{ __('common.total') }}</div>
            <div class="val neg"><b>{{ $fmt($r->grand_total) }}</b></div>
            {{-- (٢٢/٩) معادلة الإجمالي — بتتكتب لما تقفل بالظبط على الأرقام المخزّنة --}}
            @if (abs((float) $r->subtotal - (float) $r->discount + (float) $r->tax_total - (float) $r->grand_total) < 0.01)
                <div class="sub2"><span dir="ltr" style="display:inline-block">{{ preg_replace('/(\p{Arabic}+(?:[ \/]\p{Arabic}+)*)/u', '$1'.html_entity_decode('&lrm;'), __('uic.ret_total_eq', ['t' => $fmt($r->grand_total), 's' => $fmt($r->subtotal), 'd' => $fmt($r->discount), 'x' => $fmt($r->tax_total)])) }}</span></div>
            @endif
            <div class="sub2">{{ __('uic.ret_total_sub') }}</div></div>
    </div>

    @if ($r->note)
        <div class="alert info">{{ $r->note }}</div>
    @endif

    <div class="tablewrap" id="retItems">
        <table>
            <tr>
                <th>{{ __('stock.product') }}</th>
                <th>{{ __('ops.invoice') }}</th>
                <th>{{ __('common.qty') }}</th>
                <th>{{ __('field.return_cond') }}</th>
                <th>{{ __('price.unit_price') }}</th>
                <th>{{ __('common.total') }}</th>
            </tr>
            @foreach ($r->items as $it)
                <tr>
                    <td>@if ($it->product)<a href="{{ route('erp.products.show', $it->product) }}"><b>{{ $it->product->displayName() }}</b></a>@else <b>—</b> @endif</td>
                    {{-- ⚠️ **الفاتورة الأصلية جنب كل بند** — دي الحاجة
                         اللي بتخلّي المراجعة ممكنة: السعر جه منين. --}}
                    <td>@if ($it->invoiceItem?->invoice)<a href="{{ route('ops.invoice', $it->invoiceItem->invoice) }}">{{ $it->invoiceItem->invoice->number }}</a>@else <span style="color:var(--muted)">—</span> @endif</td>
                    <td class="num">{{ number_format($it->qty) }}</td>
                    <td>
                        <span class="badge {{ $it->isDamaged() ? 'b-red' : 'b-green' }}">
                            {{ $it->conditionLabel() }}</span>
                    </td>
                    <td class="num">{{ $fmt($it->price) }}</td>
                    <td class="num"><b>{{ $fmt($it->total) }}</b></td>
                </tr>
            @endforeach
        </table>
    </div>

    @if ($r->entry)
        <div class="alert good">
            {{ __('field.return_entry_posted', ['memo' => $r->entry->memo]) }}
        </div>
    @endif
</div>

@endsection
