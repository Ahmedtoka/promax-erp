{{--
    الفلتر السريع للأوامر (٩/١١/٢٠٢٦ — طلب المالك «أفلتر بالأوامر المستنية بس»).
    أول حاجة في الشاشة. بيحافظ على باقي الفلاتر ويصفّر الصفحة والصنف.

    @include('ops._po_scope', ['current' => $f['scope'] ?? '', 'withAll' => true])
      withAll  true = فيه «كل الأوامر» (شاشة الأوامر)، false = المفتوح بس (شاشة النواقص)
--}}
@php
    $scopes = ($withAll ?? false) ? ['' => 'scope_all'] : [];
    $scopes += ['open' => 'scope_open', 'awaiting' => 'scope_awaiting', 'covered' => 'scope_covered'];
    $scopeIcons = ['' => '🗂️', 'open' => '📂', 'awaiting' => '⏳', 'covered' => '📦'];
    $cur = (string) ($current ?? '');
@endphp
<nav class="psc" aria-label="{{ __('shortage.scope_label') }}">
    <span class="psc-l">{{ __('shortage.scope_label') }}</span>
    <div class="psc-seg" role="tablist">
        @foreach ($scopes as $val => $key)
            <a role="tab" aria-selected="{{ $cur === $val ? 'true' : 'false' }}" @class(['psc-o', 'on' => $cur === $val])
               href="{{ request()->fullUrlWithQuery(['scope' => $val === '' ? null : $val, 'page' => null, 'product' => null]) }}">
                {{ $scopeIcons[$val] }} {{ __('shortage.'.$key) }}</a>
        @endforeach
    </div>
    @if ($cur !== '')
        <span class="psc-h">{{ __('shortage.scope_hint_'.$cur) }}</span>
    @endif
</nav>
@once
<style>
.psc{display:flex;align-items:center;gap:8px 14px;flex-wrap:wrap;margin-bottom:14px}
.psc-l{font-size:12.5px;font-weight:900;color:var(--text)}
.psc-seg{display:inline-flex;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:3px;gap:2px;flex-wrap:wrap}
.psc-o{font-size:13px;font-weight:800;color:var(--muted);padding:7px 14px;border-radius:9px;text-decoration:none;white-space:nowrap;transition:background .15s,color .15s}
.psc-o:hover{color:var(--royal-blue)}
.psc-o.on{background:var(--card);color:var(--royal-blue);box-shadow:0 1px 2px rgba(10,10,15,.08),0 2px 8px rgba(18,57,155,.12)}
.psc-o:focus-visible{outline:2px solid var(--royal-blue);outline-offset:1px}
.psc-h{font-size:11.5px;color:var(--muted);flex-basis:100%;line-height:1.6}
@media (max-width:640px){.psc-seg{display:grid;grid-template-columns:1fr 1fr;width:100%}.psc-o{text-align:center;padding:8px 6px}}
</style>
@endonce
