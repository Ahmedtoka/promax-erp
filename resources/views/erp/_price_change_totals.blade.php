{{--
    جدول «الأثر بالفلوس» + جدول «اللي اتغير في الأسعار» — مشتركين بين
    معاينة تعديل الأسعار وصفحة السجل (Lane 13).

    $totals   مصفوفة totals من نتيجة المعاينة/الملخص (invoice/po/all) — أي مفتاح ناقص يتحسب صفر
    $items    item_changes
    $trigger  مصدر التعديل (list|chain|branch|client|invoice)

    ⚠️ مفيش كويري هنا — كل حاجة جاية من الكنترولر.
--}}
@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $sign = fn ($n) => (float) $n > 0.004 ? 'neg' : ((float) $n < -0.004 ? 'pos' : '');
    $zero = ['before_net' => 0, 'before_tax' => 0, 'before_grand' => 0, 'after_net' => 0, 'after_tax' => 0, 'after_grand' => 0, 'diff_net' => 0, 'diff_tax' => 0, 'diff_grand' => 0];
    $trRows = [
        'row_invoices' => $totals['invoice'] ?? $zero,
        'row_pos' => $totals['po'] ?? $zero,
        'row_all' => $totals['all'] ?? $zero,
    ];
    $isListItems = $trigger === 'list' || $trigger === 'invoice';
@endphp

<div class="card">
    <h3>💰 {{ __('reprice.totals_title') }}</h3>
    <div class="tablewrap"><table>
        <thead>
            <tr>
                <th rowspan="2">{{ __('reprice.col_docs') }}</th>
                <th colspan="3">{{ __('reprice.before') }}</th>
                <th colspan="3">{{ __('reprice.after') }}</th>
                <th colspan="3">{{ __('reprice.diff') }}</th>
            </tr>
            <tr>
                @foreach (['before', 'after', 'diff'] as $g)
                    <th class="num">{{ __('reprice.net') }}</th><th class="num">{{ __('reprice.tax') }}</th><th class="num">{{ __('reprice.grand') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($trRows as $label => $t)
                <tr @if ($label === 'row_all') style="font-weight:800" @endif>
                    <td>{{ __('reprice.'.$label) }}</td>
                    @foreach (['before', 'after'] as $g)
                        @foreach (['net', 'tax', 'grand'] as $k)
                            <td class="num">{{ $fmt($t[$g.'_'.$k] ?? 0) }}</td>
                        @endforeach
                    @endforeach
                    @foreach (['net', 'tax', 'grand'] as $k)
                        <td class="num {{ $sign($t['diff_'.$k] ?? 0) }}" dir="ltr">{{ $fmt($t['diff_'.$k] ?? 0) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3>🏷 {{ __('reprice.items_title') }} <span class="side">{{ count($items) }}</span></h3>
    @if ($items === [])
        <div class="alert info">{{ __('reprice.no_items') }}</div>
    @elseif ($isListItems)
        <div class="tablewrap"><table>
            <thead><tr><th>{{ __('reprice.col_product') }}</th><th class="num">{{ __('reprice.col_old') }}</th><th class="num">{{ __('reprice.col_new') }}</th><th class="num">{{ __('reprice.diff') }}</th></tr></thead>
            <tbody>
                @foreach ($items as $it)
                    <tr>
                        <td>{{ $it['name'] ?? '' }}</td>
                        <td class="num">{{ $fmt($it['old'] ?? 0) }}</td>
                        <td class="num">{{ $fmt($it['new'] ?? 0) }}</td>
                        <td class="num {{ $sign(($it['new'] ?? 0) - ($it['old'] ?? 0)) }}" dir="ltr">{{ $fmt(($it['new'] ?? 0) - ($it['old'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @else
        <div class="tablewrap"><table>
            <thead><tr><th>{{ __('reprice.col_client') }}</th><th>{{ __('reprice.col_old_list') }}</th><th>{{ __('reprice.col_new_list') }}</th></tr></thead>
            <tbody>
                @foreach ($items as $it)
                    <tr>
                        <td><a href="{{ route('erp.clients.show', $it['client_id']) }}">{{ $it['name'] ?? '' }}</a></td>
                        <td>{{ $it['old_list_name'] ?? __('reprice.no_list') }}</td>
                        <td>{{ $it['new_list_name'] ?? __('reprice.no_list') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</div>
