{{-- تابات مراجعة إعادة التشغيل + الكثافة المشتركة + سكريبت العلامات (٥/١١ — إعادة تظبيط ١٢/١١) --}}
@php
    $tabs = [
        'erp.review' => __('review.tab_dashboard'),
        'erp.review.data' => __('review.tab_data'),
        'erp.review.money' => __('review.tab_money'),
        'erp.review.stock' => __('review.tab_stock'),
        // (١١/١١) المحاسب والأدمن بس — مدير القناة مالوش التاب دي
        'erp.review.docs' => __('review.tab_docs'),
        'erp.review.docs.people' => __('review.people_tab'),
    ];
    $tabs = array_filter($tabs, fn ($r) => \App\Support\Access::allows(auth()->user(), $r), ARRAY_FILTER_USE_KEY);
    $here = request()->route()?->getName();
@endphp
<nav class="rv-tabs" aria-label="{{ __('review.tab_dashboard') }}">
    @foreach ($tabs as $r => $label)
        <a href="{{ route($r) }}" class="{{ $here === $r ? 'on' : '' }}" @if ($here === $r) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

<style>
/* ═══ شاشات المراجعة: كثافة شغل (بلاغ أحمد ١٢/١١ «الجداول بايظة والزراير كبيرة») ═══
   ⚠️ متقفلة على الصفحة اللي فيها التابات دي بس (`main:has(.rv-tabs)`) — باقي
   السيستم مالوش دعوة. المحاسب بيقعد ساعات قدام الشاشات دي: صف أوطى، زرار
   على قد الكلام، والجدول يبان كله من غير سكرول بالعرض. */
main:has(.rv-tabs) .card{padding:12px 14px;margin-bottom:12px}
main:has(.rv-tabs) table{font-size:12.5px}
main:has(.rv-tabs) th{padding:7px 8px;font-size:11.5px}
main:has(.rv-tabs) td{padding:6px 8px;max-width:260px}
main:has(.rv-tabs) .btn{padding:5px 12px;font-size:12px;border-radius:8px;line-height:1.35}
main:has(.rv-tabs) .btn.sm{padding:3px 9px;font-size:11.5px;border-radius:7px}
main:has(.rv-tabs) input:not([type=checkbox]):not([type=radio]),main:has(.rv-tabs) select{padding:5px 9px;font-size:12.5px;border-radius:8px}
main:has(.rv-tabs) .fl{min-width:0;gap:3px}
main:has(.rv-tabs) .fl>span{font-size:10.5px}
main:has(.rv-tabs) .kpis{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:12px}
main:has(.rv-tabs) .kpi{padding:9px 12px}
main:has(.rv-tabs) .kpi .val{font-size:22px}
main:has(.rv-tabs) .tbl-xl{padding:3px 9px;font-size:11px;border-radius:7px}
main:has(.rv-tabs) .tbl-bar{margin-bottom:6px}
main:has(.rv-tabs) .searchbar{gap:8px;margin-bottom:10px}

.rv-tabs{display:flex;gap:2px;flex-wrap:wrap;margin-bottom:12px;padding:3px;background:var(--card2);border:1px solid var(--border);border-radius:11px;width:max-content;max-width:100%}
.rv-tabs a{padding:6px 13px;border-radius:8px;font-weight:800;font-size:12.5px;color:var(--muted);white-space:nowrap;text-decoration:none}
.rv-tabs a:hover{color:var(--text);background:var(--card)}
.rv-tabs a.on{background:var(--card);color:var(--royal-blue);box-shadow:0 1px 2px rgba(10,10,15,.08),0 0 0 1px var(--border)}

/* ✓ ✗ على بند */
.rv{display:inline-flex;gap:3px;align-items:center;margin-top:3px}
.rv button{border:1px solid var(--border);background:var(--card);border-radius:6px;min-width:24px;height:22px;cursor:pointer;font-weight:900;font-size:12px;line-height:1;color:var(--muted);padding:0}
.rv button:hover{border-color:var(--royal-blue);color:var(--royal-blue)}
.rv[data-status="ok"] .y{background:var(--green);border-color:var(--green);color:#fff}
.rv[data-status="issue"] .n{background:var(--red);border-color:var(--red);color:#fff}
.rv .meta{font-size:10px;color:var(--muted);margin-inline-start:3px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
tr.rv-done td{background:#F0FDF4}
tr.rv-issue td{background:#FEF2F2}
.rv-miss{display:inline-block;padding:2px 8px;border-radius:6px;background:#FFF4E5;border:1px dashed #F59E0B;color:#B45309;font-weight:800;font-size:11px}
.rv-val{font-weight:700}
.rv-sub{font-size:11px;color:var(--muted)}
.rv-bar{height:8px;background:var(--border);border-radius:99px;overflow:hidden;margin:8px 0 4px}
.rv-bar i{display:block;height:100%;background:var(--green);transition:width .35s ease}
.rv-bar b{display:block;height:100%;background:var(--red);float:inline-start;transition:width .35s ease}

/* الترقيم الموحّد لشاشات المراجعة (`erp.review._pager`) */
.rv-pager{display:flex;gap:4px;align-items:center;flex-wrap:wrap;margin-top:10px;font-size:12px}
.rv-pager a,.rv-pager span.cur{min-width:28px;height:26px;display:inline-flex;align-items:center;justify-content:center;padding:0 8px;border-radius:7px;border:1px solid var(--border);background:var(--card);color:var(--text);font-weight:800;text-decoration:none}
.rv-pager a:hover{border-color:var(--royal-blue);color:var(--royal-blue)}
.rv-pager span.cur{background:var(--royal-blue);border-color:var(--royal-blue);color:#fff}
.rv-pager .gap{color:var(--muted);padding:0 2px}
.rv-pager .info{color:var(--muted);margin-inline-end:auto}
.rv-pager .info b{color:var(--text)}
.rv-pager select{padding:3px 6px!important;font-size:11.5px!important;width:auto}
@media (max-width:640px){
    .rv-tabs{width:100%;overflow-x:auto;flex-wrap:nowrap}
    main:has(.rv-tabs) .kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
}
</style>

<script>
// ✓ / ✗ على بند — بيحفظ على طول ويلوّن الصف من غير ريلود
window.rvMark = async function (btn, status) {
    const box = btn.closest('.rv');
    const cur = box.dataset.status;
    let note = null;
    if (status === 'issue') {
        note = prompt(@js(__('review.issue_prompt')), box.dataset.note || '');
        if (note === null) return;
    }
    if (status === cur && status === 'ok') status = 'clear';
    await rvSend(box.dataset.type, box.dataset.id, [box.dataset.item], status, note, {});
};

window.rvSend = async function (type, id, items, status, note, extra) {
    const body = new FormData();
    body.append('_token', @js(csrf_token()));
    body.append('entity_type', type);
    body.append('entity_id', id);
    items.forEach(i => body.append('items[]', i));
    body.append('status', status);
    if (note) body.append('note', note);
    Object.entries(extra || {}).forEach(([k, v]) => { if (v !== null && v !== '') body.append(k, v); });
    const res = await fetch(@js(route('erp.review.mark')), { method: 'POST', body, headers: { 'Accept': 'application/json' } });
    if (!res.ok) { alert('⚠️ ' + res.status); return null; }
    const data = await res.json();
    document.querySelectorAll('.rv[data-type="' + type + '"][data-id="' + id + '"]').forEach(box => {
        const c = data.checks[box.dataset.item];
        box.dataset.status = c ? c.status : '';
        box.dataset.note = c && c.note ? c.note : '';
        const meta = box.querySelector('.meta');
        if (meta) meta.textContent = c ? (c.note ? c.note + ' · ' : '') + (c.by || '') : '';
        box.title = c ? (c.by || '') + ' · ' + (c.at || '') + (c.note ? '\n' + c.note : '') : '';
    });
    const row = document.querySelector('tr[data-row="' + type + '-' + id + '"]');
    if (row) {
        const boxes = row.querySelectorAll('.rv[data-row-item]');
        const states = [...boxes].map(b => b.dataset.status);
        row.classList.toggle('rv-issue', states.includes('issue'));
        row.classList.toggle('rv-done', states.length > 0 && states.every(s => s === 'ok'));
    }
    return data;
};

window.rvAll = function (type, id, items) { if (items.length) rvSend(type, id, items, 'ok', null, {}); };

// التعديل بيفتح في تاب جديدة — لما ترجع الصفحة بتتحدّث عشان تشوف القيمة الجديدة
document.addEventListener('click', e => {
    if (e.target.closest('a.rv-edit')) sessionStorage.setItem('rvReload', '1');
});
window.addEventListener('focus', () => {
    if (sessionStorage.getItem('rvReload')) { sessionStorage.removeItem('rvReload'); location.reload(); }
});
</script>
