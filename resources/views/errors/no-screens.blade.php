@extends('layouts.system')

@section('title', __('perm.no_screens_title'))

@section('content')

<div class="card">
    <h3>🔒 {{ __('perm.no_screens_title') }}</h3>
    <div class="alert warn">{{ __('perm.no_screens_body') }}</div>
</div>

@endsection
