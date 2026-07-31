@extends('layouts.admin')

@section('title', 'المناديب')

@section('content')

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    @foreach ($reps as $rep)
        <div class="bg-white rounded-2xl p-5 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-full grid place-items-center text-white font-bold
                                {{ $rep['type'] === 'كورير' ? 'bg-blue-600' : 'bg-emerald-600' }}">
                        {{ mb_substr($rep['name'], 0, 1) }}
                    </div>
                    <div>
                        <div class="font-extrabold">{{ $rep['name'] }}</div>
                        <div class="text-[11px] text-slate-500">{{ $rep['code'] }} • {{ $rep['type'] }}</div>
                    </div>
                </div>
                <span class="text-[11px] font-bold px-3 py-1 rounded-full bg-{{ $rep['statusColor'] }}-100 text-{{ $rep['statusColor'] }}-700">
                    {{ $rep['status'] }}
                </span>
            </div>

            <div class="text-xs text-slate-500 mb-3">📍 الزون: <span class="font-bold text-slate-700">{{ $rep['zone'] }}</span></div>

            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="bg-slate-50 rounded-xl py-2.5">
                    <div class="font-extrabold text-sm">{{ $rep['metric'] }}</div>
                    <div class="text-[10px] text-slate-500">{{ $rep['type'] === 'كورير' ? 'قيمة المسلم' : 'المبيعات' }}</div>
                </div>
                <div class="bg-slate-50 rounded-xl py-2.5">
                    <div class="font-extrabold text-sm">{{ $rep['visits'] }}</div>
                    <div class="text-[10px] text-slate-500">{{ $rep['type'] === 'كورير' ? 'التوصيلات' : 'الزيارات' }}</div>
                </div>
                <div class="bg-slate-50 rounded-xl py-2.5">
                    <div class="font-extrabold text-sm">{{ $rep['custody'] }}</div>
                    <div class="text-[10px] text-slate-500">عهدة متبقية</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- ===== تراكينج اليوم ===== --}}
<div class="bg-white rounded-2xl p-5 shadow-sm">
    <div class="font-bold mb-4">تايم لاين اليوم — كل المناديب</div>
    <div class="relative border-s-2 border-slate-200 ms-3 space-y-5">
        @foreach ($tracking as $t)
            @php
                $dot = match ($t['type']) {
                    'sale' => 'bg-emerald-500',
                    'deliver' => 'bg-teal-500',
                    'in' => 'bg-blue-500',
                    'out' => 'bg-rose-500',
                    'req' => 'bg-amber-500',
                    default => 'bg-violet-500',
                };
            @endphp
            <div class="ms-5 relative">
                <span class="absolute -start-[27px] top-1 w-3.5 h-3.5 rounded-full border-2 border-white {{ $dot }}"></span>
                <div class="text-sm font-semibold">{{ $t['event'] }}</div>
                <div class="text-xs text-slate-500">{{ $t['rep'] }} • {{ $t['time'] }}</div>
            </div>
        @endforeach
    </div>
</div>

@endsection
