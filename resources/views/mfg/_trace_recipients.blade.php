{{-- عملاء باتش تام (كل التوائم) + اللي لسه في عربيات المناديب — Genealogy::recipientsFor / inVans --}}
<h4>{{ __('mfg.trace_recipients_title') }}</h4>
<div class="tablewrap">
    <table>
        <tr>
            <th>{{ __('mfg.col_client') }}</th>
            <th data-nosum>{{ __('mfg.col_via') }}</th>
            <th data-nosum>{{ __('mfg.col_ref') }}</th>
            <th class="num">{{ __('mfg.col_qty') }}</th>
            <th data-nosum>{{ __('mfg.col_received_on') }}</th>
        </tr>
        @forelse ($recipients as $r)
            <tr>
                <td>{{ $r['client']->code }} — {{ $r['client']->displayName() }}</td>
                <td><span class="badge {{ ['invoice' => 'b-blue', 'sales_order' => 'b-purple', 'gift' => 'b-gold'][$r['via']] ?? 'b-gray' }}">{{ __('mfg.via_'.$r['via']) }}</span></td>
                <td class="num">{{ $r['ref'] }}</td>
                <td class="num">{{ number_format($r['qty']) }}</td>
                <td class="num s">{{ $r['date']?->format('Y-m-d') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:18px">{{ __('mfg.no_recipients') }}</td></tr>
        @endforelse
    </table>
</div>

<h4>{{ __('mfg.trace_vans_title') }}</h4>
<div class="tablewrap">
    <table>
        <tr>
            <th>{{ __('mfg.col_rep') }}</th>
            <th class="num">{{ __('mfg.col_qty') }}</th>
            <th data-nosum>{{ __('mfg.col_custody_date') }}</th>
        </tr>
        @forelse ($vans as $v)
            <tr>
                <td>{{ $v['rep']->displayName() }}</td>
                <td class="num">{{ number_format($v['qty']) }}</td>
                <td class="num s">{{ $v['date'] ? \Illuminate\Support\Carbon::parse($v['date'])->format('Y-m-d') : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:18px">{{ __('mfg.no_vans') }}</td></tr>
        @endforelse
    </table>
</div>
