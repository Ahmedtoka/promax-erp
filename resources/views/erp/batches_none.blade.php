@extends('layouts.system')

@section('title', __('stock.batch_report'))

@section('content')

<div class="card">
    <h3>🗓️ {{ __('stock.batch_report') }}</h3>
    <div class="alert warn">{{ __('stock.batch_report_missing') }}</div>
    <div style="font-size:12px;color:var(--muted);margin-top:8px">
        {{ __('stock.batch_report_missing_hint') }}
    </div>
</div>

@endsection
