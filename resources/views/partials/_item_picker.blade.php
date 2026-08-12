{{--
    بارشيال مشترك: منتقي أصناف على شكل «ليست بحث» بدل السيلكت الجاف.
    Shared partial: a searchable product LIST with MULTI-SELECT
    checkboxes (12/8) — the pattern used in تعديل إذن الصرف (wh/pick)
    و استلام التوريد (wh/receipts).

    بيرسم خانة بحث + قايمة نتايج بتتفلتر في المتصفح (من غير راوند تريب).
    كل صف فيه تشيك بوكس — دوسة على الصف بتعلّم عليه، وزرار
    «إضافة (X)» تحت بينده دالة الاختيار للشاشة **لكل** صنف متعلّم
    عليه. «اختيار الكل» بيعلّم على النتايج الظاهرة.

    باراميترز الإنكلود:
      id          — بادئة فريدة للعناصر والدوال (مثال: 'grn')
      catalog     — مصفوفة PHP للأصناف: [id, code, name, name_ar, name_en, image]
      onPick      — اسم دالة جافاسكربت عامّة بتتندَه بـ id الصنف —
                    بتتندَه مرة لكل صنف متعلّم عليه عند «إضافة»
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
                box-shadow:0 10px 30px rgba(0,0,0,.12);max-height:320px;overflow-y:auto">
        <div id="{{ $pkId }}List"></div>
        {{-- شريط الإضافة — ثابت تحت مهما اتعمل سكرول --}}
        <div style="position:sticky;bottom:0;background:#fff;border-top:1px solid var(--border);
                    padding:8px 10px;display:flex;gap:8px;align-items:center">
            <button class="btn sm" type="button" id="{{ $pkId }}AllBtn"
                    onclick="{{ $pkId }}PickerAll()">{{ __('field.select_all') }}</button>
            <span style="flex:1"></span>
            <button class="btn sm gold" type="button" id="{{ $pkId }}AddBtn" disabled
                    onclick="{{ $pkId }}PickerAdd()">{{ __('common.add') }} (0)</button>
        </div>
    </div>
</div>

<script>
(function () {
    const CAT = {!! $pkJson !!};
    window.{{ $pkVar }} = CAT;

    const EMPTY = {!! json_encode($pkEmpty, JSON_UNESCAPED_UNICODE) !!};
    const ADD_LBL = {!! json_encode(__('common.add'), JSON_UNESCAPED_UNICODE) !!};
    const SEL_ALL = {!! json_encode(__('field.select_all'), JSON_UNESCAPED_UNICODE) !!};
    const UNSEL_ALL = {!! json_encode(__('field.unselect_all'), JSON_UNESCAPED_UNICODE) !!};
    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const box = () => document.getElementById('{{ $pkId }}Results');
    const list = () => document.getElementById('{{ $pkId }}List');
    const inp = () => document.getElementById('{{ $pkId }}Search');

    // المتعلّم عليه — بيعيش عبر البحث والفلترة لحد ما «إضافة» تتداس
    const sel = new Set();
    // آخر نتايج مرسومة — «اختيار الكل» بيشتغل عليها
    let hits = [];

    // تحديث زرار «إضافة (X)» وليبل «اختيار الكل»
    function bar() {
        const add = document.getElementById('{{ $pkId }}AddBtn');
        if (add) {
            add.textContent = ADD_LBL + ' (' + sel.size + ')';
            add.disabled = sel.size === 0;
        }
        const all = document.getElementById('{{ $pkId }}AllBtn');
        if (all) {
            const every = hits.length > 0 && hits.every(p => sel.has(p.id));
            all.textContent = every ? UNSEL_ALL : SEL_ALL;
        }
    }

    function render() {
        const b = box();
        b.style.display = 'block';
        list().innerHTML = hits.length === 0
            ? '<div style="padding:14px;text-align:center;color:var(--muted);font-size:12px">' + esc(EMPTY) + '</div>'
            : hits.map(p =>
                '<div data-pid="' + p.id + '" onclick="{{ $pkId }}PickerToggle(' + p.id + ')" ' +
                'style="display:flex;align-items:center;gap:10px;padding:9px 13px;cursor:pointer;' +
                'border-bottom:1px solid var(--border);background:' + (sel.has(p.id) ? '#E8F1FF' : '#fff') + '">' +
                '<input type="checkbox" ' + (sel.has(p.id) ? 'checked ' : '') +
                'style="pointer-events:none;width:17px;height:17px;flex-shrink:0">' +
                (p.image
                    ? '<img src="' + esc(p.image) + '" style="width:52px;height:52px;object-fit:contain;border-radius:6px;border:1px solid var(--border);background:#fff">'
                    : '<span style="width:52px;height:52px;border:1px dashed var(--border);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--muted)">📦</span>') +
                '<span style="flex:1;min-width:0"><b style="font-size:12.5px">' + esc(p.name) + '</b>' +
                '<span style="display:block;font-size:10.5px;color:var(--muted)">' + esc(p.code) + '</span></span>' +
                '</div>').join('');
        bar();
    }

    // البحث: اسم عربي/إنجليزي أو كود — أو فوكس/فاضي يفتح الكل
    window.{{ $pkId }}PickerSearch = function () {
        const q = (inp().value || '').trim().toLowerCase();
        hits = CAT.filter(p =>
            q === '' ||
            (p.name || '').toLowerCase().includes(q) ||
            (p.name_ar || '').includes(q) ||
            (p.name_en || '').toLowerCase().includes(q) ||
            (p.code || '').toLowerCase().includes(q)
        );
        render();
    };

    // دوسة على الصف بتقلب علامته — من غير إعادة رسم (السكرول بيفضل مكانه)
    window.{{ $pkId }}PickerToggle = function (id) {
        if (sel.has(id)) { sel.delete(id); } else { sel.add(id); }
        const row = list().querySelector('[data-pid="' + id + '"]');
        if (row) {
            const cb = row.querySelector('input[type=checkbox]');
            if (cb) { cb.checked = sel.has(id); }
            row.style.background = sel.has(id) ? '#E8F1FF' : '#fff';
        }
        bar();
    };

    // «اختيار الكل» على النتايج الظاهرة — لو كلها متعلّمة بيشيل العلامات
    window.{{ $pkId }}PickerAll = function () {
        const every = hits.length > 0 && hits.every(p => sel.has(p.id));
        hits.forEach(p => { if (every) { sel.delete(p.id); } else { sel.add(p.id); } });
        render();
    };

    // «إضافة (X)» — بينده onPick لكل صنف متعلّم عليه بنفس عقد
    // النداء الواحد القديم، فمفيش شاشة محتاجة تتغير. بيقفل الليست
    // ويصفّر الاختيار الأول عشان أي reset جوه onPick ميلخبطش.
    window.{{ $pkId }}PickerAdd = function () {
        const ids = Array.from(sel);
        if (ids.length === 0) { return; }
        sel.clear();
        window.{{ $pkId }}PickerReset();
        ids.forEach(function (id) { {{ $onPick }}(id); });
    };

    // القفل = إلغاء جلسة الاختيار — عشان فتحة تانية (أو دايالوج
    // اتلغى واتفتح تاني) ماتلاقيش علامات قديمة من جلسة مهجورة
    window.{{ $pkId }}PickerClose = function () {
        const b = box();
        if (b) b.style.display = 'none';
        sel.clear();
        bar();
    };
    window.{{ $pkId }}PickerReset = function () { const i = inp(); if (i) i.value = ''; window.{{ $pkId }}PickerClose(); };

    // قفل القايمة عند الضغط بره
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#{{ $pkId }}Search') && !e.target.closest('#{{ $pkId }}Results')) {
            window.{{ $pkId }}PickerClose();
        }
    });
})();
</script>
