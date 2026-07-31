@extends('layouts.system')

@section('title', __('stock.warehouse_overview'))

@section('content')

<div class="card">
    <h3>🏭 {{ __('stock.warehouses') }}</h3>
    <div class="alert warn">{{ __('stock.no_warehouse') }}</div>
</div>

@endsection
