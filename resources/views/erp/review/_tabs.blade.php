{{-- تابات مراجعة إعادة التشغيل + سكريبت العلامات المشترك (٥/١١) --}}
@php
    $tabs = [
        'erp.review' => __('review.tab_dashboard'),
        'erp.review.data' => __('review.tab_data'),
        'erp.review.money' => __('review.tab_money'),
        'erp.review.stock' => __('review.tab_stock'),
    ];
    // الفيديوهات بتترفع في storage/app/docs/review — نفس مكان «الدليل»
    $videos = ['erp.review.data' => 'review/phase1.mp4', 'erp.review.money' => 'review/phase2.mp4', 'erp.review.stock' => 'review/phase3.mp4'];
    $here = request()->route()?->getName();
@endphp
<div class="rv-tabs">
    @foreach ($tabs as $r => $label)
        <a href="{{ route($r) }}" class="{{ $here === $r ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
    @if (isset($videos[$here]) && \App\Support\Access::allows(auth()->user(), 'docs.file'))
        <a class="vid" href="{{ route('docs.file', $videos[$here]) }}" target="_blank" rel="noopener">{{ __('review.video') }}</a>
    @endif
</div>

<style>
.rv-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.rv-tabs a{padding:9px 16px;border-radius:999px;background:var(--card);border:1px solid var(--border);font-weight:800;font-size:13px;color:var(--text)}
.rv-tabs a.on{background:var(--royal-blue);color:#fff;border-color:var(--royal-blue)}
.rv-tabs a.vid{margin-inline-start:auto;background:#FFF7D6;border-color:#E8C548;color:#7A5A00}
.rv{display:inline-flex;gap:3px;align-items:center;margin-top:4px}
.rv button{border:1px solid var(--border);background:var(--card);border-radius:7px;min-width:28px;height:26px;cursor:pointer;font-weight:900;font-size:13px;line-height:1;color:var(--muted)}
.rv[data-status="ok"] .y{background:#16A34A;border-color:#16A34A;color:#fff}
.rv[data-status="issue"] .n{background:#DC2626;border-color:#DC2626;color:#fff}
.rv .meta{font-size:10px;color:var(--muted);margin-inline-start:4px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
tr.rv-done td{background:#F0FDF4}
tr.rv-issue td{background:#FEF2F2}
.rv-val{font-weight:700}
.rv-sub{font-size:11px;color:var(--muted)}
.rv-bar{height:10px;background:var(--border);border-radius:99px;overflow:hidden;margin:10px 0 6px}
.rv-bar i{display:block;height:100%;background:linear-gradient(90deg,#16A34A,#22C55E)}
.rv-bar b{display:block;height:100%;background:#DC2626;float:inline-start}
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

window.rvAll = function (type, id, items) { rvSend(type, id, items, 'ok', null, {}); };

// التعديل بيفتح في تاب جديدة — لما ترجع الصفحة بتتحدّث عشان تشوف القيمة الجديدة
document.addEventListener('click', e => {
    if (e.target.closest('a.rv-edit')) sessionStorage.setItem('rvReload', '1');
});
window.addEventListener('focus', () => {
    if (sessionStorage.getItem('rvReload')) { sessionStorage.removeItem('rvReload'); location.reload(); }
});
</script>
