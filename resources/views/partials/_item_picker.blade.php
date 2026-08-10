{{--
    بارشيال مشترك: منتقي أصناف على شكل «ليست بحث» بدل السيلكت الجاف.
    Shared partial: a searchable product LIST (type to filter by
    name/code, click to add) — the same pattern used in تسليم العهدة
    (ops/handout) و تسليم PO (ops/po_handout) و التحويلات (wh/transfer_new).

    بيرسم خانة بحث + قايمة نتايج بتتفلتر في المتصفح (من غير راوند تريب).
    كل صف مضغوط بيندَه دالة الاختيار اللي الشاشة بتوفّرها.

    باراميترز الإنكلود:
      id          — بادئة فريدة للعناصر والدوال (مثال: 'grn')
      catalog     — مصفوفة PHP للأصناف: [id, code, name, name_ar, name_en, image]
      onPick      — اسم دالة جافاسكربت عامّة بتتندَه بـ id الصنف عند الضغط
      placeholder — اختياري: نص التلميح (الافتراضي field.search_product_ph)
      emptyText   — اختياري: نص «مفيش نتايج» (الافتراضي common.no_results)

    بيعرّض الكتالوج على window.PICKER_<ID> عشان addRow في الشاشة يقرا منه.
    ملاحظة: أسماء الحقول اللي بتتبعت للسيرفر مسؤولية الشاشة نفسها —
    البارشيال ده اختيار بس، مش تسعير ولا كميات.
--}}
@php
    $pkId    = $id;
    $pkVar   = 'PICKER_'.\Illuminate\Support\Str::upper(preg_replace('/[^A-Za-z0-9_]/', '', (string) $id));
    $pkPh    = $placeholder ?? __('field.search_product_ph');
    $pkEmpty = $emptyText ?? __('common.no_results');
    $pkJson  = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

<div style="position:relative">
    <input type="search" id="{{ $pkId }}Search" autocomplete="off" style="width:100%"
           placeholder="🔍 {{ $pkPh }}"
           oninput="{{ $pkId }}PickerSearch()" onfocus="{{ $pkId }}PickerSearch()">
    <div id="{{ $pkId }}Results"
         style="display:none;position:absolute;top:calc(100% + 4px);inset-inline:0;z-index:60;
                background:#fff;border:1px solid var(--border);border-radius:12px;
                box-shadow:0 10px 30px rgba(0,0,0,.12);max-height:320px;overflow-y:auto"></div>
</div>

<script>
(function () {
    const CAT = {!! $pkJson !!};
    window.{{ $pkVar }} = CAT;

    const EMPTY = {!! json_encode($pkEmpty, JSON_UNESCAPED_UNICODE) !!};
    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const box = () => document.getElementById('{{ $pkId }}Results');
    const inp = () => document.getElementById('{{ $pkId }}Search');

    // البحث: اسم عربي/إنجليزي أو كود — أو فوكس/فاضي يفتح الكل
    window.{{ $pkId }}PickerSearch = function () {
        const q = (inp().value || '').trim().toLowerCase();
        const hits = CAT.filter(p =>
            q === '' ||
            (p.name || '').toLowerCase().includes(q) ||
            (p.name_ar || '').includes(q) ||
            (p.name_en || '').toLowerCase().includes(q) ||
            (p.code || '').toLowerCase().includes(q)
        );

        const b = box();
        b.style.display = 'block';
        b.innerHTML = hits.length === 0
            ? '<div style="padding:14px;text-align:center;color:var(--muted);font-size:12px">' + esc(EMPTY) + '</div>'
            : hits.map(p =>
                '<div onclick="{{ $onPick }}(' + p.id + ')" ' +
                'style="display:flex;align-items:center;gap:10px;padding:9px 13px;cursor:pointer;border-bottom:1px solid var(--border)">' +
                (p.image
                    ? '<img src="' + esc(p.image) + '" style="width:52px;height:52px;object-fit:contain;border-radius:6px;border:1px solid var(--border);background:#fff">'
                    : '<span style="width:52px;height:52px;border:1px dashed var(--border);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--muted)">📦</span>') +
                '<span style="flex:1;min-width:0"><b style="font-size:12.5px">' + esc(p.name) + '</b>' +
                '<span style="display:block;font-size:10.5px;color:var(--muted)">' + esc(p.code) + '</span></span>' +
                '</div>').join('');
    };

    window.{{ $pkId }}PickerClose = function () { const b = box(); if (b) b.style.display = 'none'; };
    window.{{ $pkId }}PickerReset = function () { const i = inp(); if (i) i.value = ''; window.{{ $pkId }}PickerClose(); };

    // قفل القايمة عند الضغط بره
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#{{ $pkId }}Search') && !e.target.closest('#{{ $pkId }}Results')) {
            window.{{ $pkId }}PickerClose();
        }
    });
})();
</script>
