@extends('layouts.system')

@section('title', __('nav.docs'))

@section('content')

{{-- الدليل لسه ما اترفعش على السيرفر — حالة فاضية مش خطأ (٢١/١٠/٢٠٢٦) --}}
<div class="card">
    <h3>📖 {{ __('nav.docs_missing_title') }}</h3>
    <div class="alert info">{{ __('nav.docs_missing_body') }}</div>
    @if ($isAdmin)
        <p class="muted" style="margin-top:10px">{{ __('nav.docs_missing_admin') }}</p>
    @endif
</div>

@endsection
