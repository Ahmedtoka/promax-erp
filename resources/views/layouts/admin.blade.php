<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — ProMax ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- ⚠️ الخط محلي مش من جوجل. السيستم بيشتغل على XAMPP جوه الشركة،
         ورابط CDN معناه إن الشاشة بتقع على خط النظام أول ما النت
         يقطع — ونفس الملفات اللي بيستخدمها باقي السيستم. --}}
    <link rel="stylesheet" href="{{ asset('brand/promax.css') }}">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Cairo', sans-serif; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="flex min-h-screen">

    {{-- ===== Sidebar ===== --}}
    <aside class="w-64 shrink-0 bg-slate-900 text-slate-200 flex flex-col">
        <div class="p-5 flex items-center gap-3 border-b border-slate-700/60">
            <div class="w-10 h-10 rounded-xl bg-emerald-600 grid place-items-center text-white text-xl font-extrabold">P</div>
            <div>
                <div class="font-extrabold text-white leading-tight">ProMax ERP</div>
                <div class="text-[11px] text-slate-400">لوحة التحكم — ديمو</div>
            </div>
        </div>

        @php
            $nav = [
                ['route' => 'dashboard',            'label' => 'نظرة عامة',        'icon' => '📊'],
                ['route' => 'dashboard.reps',       'label' => 'المناديب',          'icon' => '👥'],
                ['route' => 'dashboard.approvals',  'label' => 'موافقات العملاء',   'icon' => '✅'],
                ['route' => 'dashboard.products',   'label' => 'المنتجات والمخزون', 'icon' => '📦'],
                ['route' => 'dashboard.orders',     'label' => 'الفواتير والـ POs', 'icon' => '🧾'],
            ];
        @endphp

        <nav class="p-3 space-y-1 flex-1">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm transition
                          {{ request()->routeIs($item['route']) ? 'bg-emerald-600 text-white font-bold' : 'hover:bg-slate-800' }}">
                    <span>{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="p-4 text-[11px] text-slate-500 border-t border-slate-700/60">
            داتا ديمو — لسه مش مربوطة بالداتابيز
        </div>
    </aside>

    {{-- ===== Main ===== --}}
    <main class="flex-1 min-w-0">
        <header class="bg-white border-b px-6 py-4 flex items-center justify-between sticky top-0 z-10">
            <h1 class="text-lg font-extrabold">@yield('title')</h1>
            <div class="flex items-center gap-3">
                <span class="text-xs bg-amber-100 text-amber-700 font-bold px-3 py-1 rounded-full">DEMO DATA</span>
                <div class="w-9 h-9 rounded-full bg-violet-600 text-white grid place-items-center font-bold text-sm">ح</div>
                <div class="text-sm font-semibold">حسام الدين <span class="text-slate-400 text-xs">Channel Manager</span></div>
            </div>
        </header>

        <div class="p-6">
            @yield('content')
        </div>
    </main>
</div>
@yield('scripts')
</body>
</html>
