{{--
    ═══════════════════════════════════════════════════════════════
    ستايل المستندات المطبوعة — الفاتورة، إذن الصرف، محضر الاستلام
    ═══════════════════════════════════════════════════════════════

    ⚠️ **مشترك عن قصد.** كان مكتوب جوه شاشة الفاتورة، وأول مستند
    تاني كان لازم ينسخ الـ80 سطر — والنسخة اللي بتتنسى بتخلّي ورقة
    تطلع من الطابعة بشكل مختلف عن أختها.

    ⚠️ `.doc-sign` مخفي على الشاشة وبيبان في الطباعة بس: مكان
    الإمضاء على الشاشة فراغ مالوش معنى، وعلى الورق هو أهم حاجة.
--}}
<style>

/* ═══ مستند بالهوية — شاشة وطباعة ═══ */
.doc{
  max-width:820px;background:var(--card);border:1px solid var(--border);
  border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--shadow);
}

/* ⚠️ **العلامة المائية (الصاعقة) لازم absolute.** الكلاس ده كان من
   غير تعريف أساسي خالص، فالـSVG كان بيترندر في الفلو العادي بعرض
   المستند كله — صفحة بيضا طويلة والإذن مزقوق تحتها. حصلت فعلاً
   على ورقة تسليم العهدة (2026-08-03) وبتأثر على الفاتورة والتحويل. */
.doc.has-bolt{position:relative}
.bolt-mark{
  position:absolute;top:40px;inset-inline-start:-60px;width:360px;height:auto;
  opacity:.05;pointer-events:none;z-index:0;transform:rotate(8deg);
}
.bolt-mark.lg{width:440px}
.doc-head,.doc-body,.doc-foot{position:relative;z-index:1}
.doc-head{
  background:var(--brand-gradient);color:#fff;padding:22px 26px;
  display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;
}
{{-- ⚠️ اللوجو الرسمي مربع (640×640) — التحكم بالارتفاع مش العرض،
     عشان أي لوجو بأي نسبة أبعاد يقعد صح في هيدر المستند --}}
.doc-logo{height:64px;width:auto;display:block}
.doc-corp{font-size:10px;letter-spacing:1.5px;opacity:.72;margin-top:6px;font-weight:600}
.doc-id{text-align:end}
.doc-kind{font-size:10.5px;letter-spacing:1.2px;opacity:.75;font-weight:600}
.doc-no{font-size:23px;font-weight:800;letter-spacing:-.5px;line-height:1.2}
.doc-date{font-size:11.5px;opacity:.75;margin-bottom:6px}
.doc-id .badge{background:rgba(255,255,255,.92)}

.doc-body{padding:22px 26px 26px}
.doc-parties{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;
  background:var(--blue-050);border-radius:var(--r-md);padding:15px 16px;margin-bottom:18px;
}
.doc-parties .k{font-size:10px;color:var(--royal-blue);font-weight:800;letter-spacing:.5px;margin-bottom:3px}
.doc-parties .v{font-weight:700;font-size:13.5px}
.s{font-size:11px;color:var(--muted)}

.doc-table th{background:var(--card2);color:var(--muted);font-size:11px;letter-spacing:.3px}
.doc-table td{padding:10px 8px}

.doc-totals{margin-top:18px;margin-inline-start:auto;max-width:320px}
.doc-totals .row{display:flex;justify-content:space-between;padding:7px 0;font-size:13px;color:var(--muted)}
.doc-totals .row.disc{color:var(--purple-500)}
.doc-totals .row.tax{color:var(--royal-blue)}
.doc-totals .row.grand{
  border-top:2px solid var(--royal-blue);margin-top:6px;padding-top:11px;
  font-size:18px;font-weight:800;color:var(--ink);
}

.doc-sign{display:none;gap:60px;margin-top:44px}
.doc-sign div{flex:1;font-size:11px;color:var(--muted);text-align:center}
.doc-sign span{display:block;border-top:1px solid var(--ink);margin-bottom:6px;height:34px}

.doc-foot{
  border-top:1px solid var(--border);padding:11px 26px;
  display:flex;justify-content:space-between;
  font-size:10px;letter-spacing:1.2px;color:var(--muted);font-weight:600;
}

/* ═══ الطباعة ═══ */
@media print{
  @page{size:A4;margin:12mm}
  .sidebar,.topbar,.flash{display:none !important}
  .main{padding:0 !important}
  .wrap{display:block !important}
  body{background:#fff}
  .doc{max-width:none;border:none;border-radius:0;box-shadow:none}
  .doc-head{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .doc-parties{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .doc-table th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .doc-sign{display:flex}
  .bolt-mark{opacity:.035 !important}
}

/* ═══ زيادات ورق المخزن ═══ */
.doc-sign.three div{max-width:none}
.doc-note{
  margin-top:16px;padding:11px 13px;border:1px dashed var(--border);
  border-radius:var(--r-md);font-size:11.5px;color:var(--muted);line-height:1.8;
}
.doc-var{font-weight:800}
.doc-var.short{color:var(--red)}
.doc-var.ok{color:var(--green)}
@media print{
  .doc-note{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
