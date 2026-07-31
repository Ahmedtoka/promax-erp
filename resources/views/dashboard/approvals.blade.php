@extends('layouts.admin')

@section('title', 'موافقات العملاء الجدد')

@section('content')

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach ($requests as $r)
        @php
            [$cls, $txt] = match ($r['status']) {
                'pending' => ['bg-slate-100 text-slate-600', 'مستني الموافقة'],
                'review' => ['bg-amber-100 text-amber-700', 'تحت المراجعة'],
                'approved' => ['bg-emerald-100 text-emerald-700', 'متوافق عليه'],
                default => ['bg-rose-100 text-rose-700', 'مرفوض'],
            };
        @endphp
        <div class="bg-white rounded-2xl p-5 shadow-sm" id="card-{{ $r['id'] }}">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <div class="font-extrabold">{{ $r['name'] }}</div>
                    <div class="text-[11px] text-slate-500">{{ $r['id'] }} • قدّمه {{ $r['rep'] }} • {{ $r['time'] }}</div>
                </div>
                <span class="text-[11px] font-bold px-3 py-1 rounded-full {{ $cls }}" id="status-{{ $r['id'] }}">{{ $txt }}</span>
            </div>

            <div class="text-sm text-slate-600 space-y-1 mb-3">
                <div>📍 {{ $r['address'] }}</div>
                <div>📞 {{ $r['phone'] }}</div>
            </div>

            <div class="flex gap-2 mb-4">
                <span class="text-[11px] font-bold px-3 py-1 rounded-full {{ $r['photo'] ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                    {{ $r['photo'] ? 'صورة المكان ✓' : 'مفيش صورة' }}
                </span>
                <span class="text-[11px] font-bold px-3 py-1 rounded-full {{ $r['docs'] ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                    {{ $r['docs'] ? 'أوراق رسمية ✓' : 'مفيش أوراق' }}
                </span>
            </div>

            @if (in_array($r['status'], ['pending', 'review']))
                <div class="flex gap-2">
                    <button onclick="decide('{{ $r['id'] }}', 'approved')"
                            class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold py-2 rounded-xl">
                        موافقة
                    </button>
                    @if ($r['status'] === 'pending')
                        <button onclick="decide('{{ $r['id'] }}', 'review')"
                                class="flex-1 border border-amber-500 text-amber-600 hover:bg-amber-50 text-sm font-bold py-2 rounded-xl">
                            مراجعة
                        </button>
                    @endif
                    <button onclick="decide('{{ $r['id'] }}', 'rejected')"
                            class="flex-1 border border-rose-500 text-rose-600 hover:bg-rose-50 text-sm font-bold py-2 rounded-xl">
                        رفض
                    </button>
                </div>
            @endif
        </div>
    @endforeach
</div>

<div class="mt-6 text-center text-xs text-slate-400">
    الأزرار ديمو — بتغيّر الحالة على الشاشة بس، من غير حفظ. لما نربط الداتابيز هتبعت نوتفيكيشن للمندوب فعلاً.
</div>

@endsection

@section('scripts')
<script>
    const labels = {
        approved: ['متوافق عليه', 'bg-emerald-100 text-emerald-700'],
        review:   ['تحت المراجعة', 'bg-amber-100 text-amber-700'],
        rejected: ['مرفوض', 'bg-rose-100 text-rose-700'],
    };

    function decide(id, status) {
        const chip = document.getElementById('status-' + id);
        chip.textContent = labels[status][0];
        chip.className = 'text-[11px] font-bold px-3 py-1 rounded-full ' + labels[status][1];
        const card = document.getElementById('card-' + id);
        card.querySelectorAll('button').forEach(b => b.remove());
    }
</script>
@endsection
