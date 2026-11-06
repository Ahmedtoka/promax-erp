{{-- زرارين ✓ ✗ على بند واحد. المتغيرات: $type, $id, $item, $check (ReviewCheck|null), $rowItem (bool),
     $fix (رابط التكميل لو البند ناقص — بيقفل ✓ و✗ لحد ما يتكمّل) --}}
@if (! empty($fix))
<span class="rv" data-type="{{ $type }}" data-id="{{ $id }}" data-item="{{ $item }}" data-status="" @if ($rowItem ?? true) data-row-item @endif>
    <a class="rv-miss rv-edit" href="{{ $fix }}" target="_blank" rel="noopener">{{ __('review.missing_fix') }}</a>
</span>
@else
<span class="rv" data-type="{{ $type }}" data-id="{{ $id }}" data-item="{{ $item }}"
      data-status="{{ $check?->status }}" data-note="{{ $check?->note }}" @if ($rowItem ?? true) data-row-item @endif
      title="{{ $check ? ($check->user?->displayName().' · '.$check->updated_at?->format('Y-m-d H:i').($check->note ? "\n".$check->note : '')) : '' }}">
    <button type="button" class="y" onclick="rvMark(this, 'ok')" aria-label="{{ __('review.ok') }}">✓</button>
    <button type="button" class="n" onclick="rvMark(this, 'issue')" aria-label="{{ __('review.issue') }}">✗</button>
    <span class="meta">{{ $check?->note ? $check->note.' · ' : '' }}{{ $check?->user?->displayName() }}</span>
</span>
@endif
