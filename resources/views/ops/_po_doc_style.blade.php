{{-- ستايل الفاتورة المؤقتة — مشترك بين الفردي والمجمع.
     ⚠️ لازم `partials._doc_style` يتضمّن قبله. --}}
<style>
/* ═══ فاتورة مؤقتة: A4 مضبوطة + هيدر مضغوط + فوتر لاصق ═══ */

.po-doc{display:flex;flex-direction:column}
.po-doc .doc-body{flex:1;display:flex;flex-direction:column}

/* ═══ الهيدر المضغوط: اللوجو وجنبه البيانات — مش تحته ═══ */
.po-doc .doc-head{
  background:#fff;color:var(--ink);
  border-bottom:3px solid var(--royal-blue);
  padding:12px 22px 10px;
  align-items:center;
}
.po-brandrow{display:flex;align-items:center;gap:14px}
.po-doc .doc-logo{height:52px}
.po-corp-name{font-size:12px;font-weight:900;letter-spacing:.4px;color:var(--ink)}
.po-corp-line{font-size:10px;color:var(--muted);margin-top:2px}
.po-corp-line b{color:var(--ink)}
.po-doc .doc-no{color:var(--ink);font-size:20px}
.po-doc .doc-date{color:var(--muted);opacity:1;font-size:10.5px;margin-bottom:2px}
.po-doc .doc-date b{color:var(--ink)}

/* العنوان الكبير */
.po-title{
  position:relative;z-index:1;text-align:center;
  font-size:22px;font-weight:900;letter-spacing:-.3px;
  color:var(--royal-blue);margin:12px 0 2px;
}

/* ═══ الأطراف: سطر المندوب/المخزن + سطر العميل بالعرض ═══ */
.po-parties{
  display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;
  padding:9px 4px 0;font-size:11px;color:var(--muted);
}
.po-party b{color:var(--ink);font-weight:800}
.po-parties .sep,.po-client-line .sep{color:var(--border);margin-inline:5px}
.po-client-line{
  padding:7px 10px;margin-top:7px;font-size:11px;color:var(--muted);
  border:1px solid var(--border);border-radius:var(--r-md);background:#fff;
  line-height:1.8;
}
.po-client-line b{color:var(--ink);font-weight:800}

/* ═══ الجدول: من غير سكرول نهائياً + صفوف الحشو ═══ */
.po-table{font-size:11px;margin-top:10px;width:100%}
.po-table th{font-size:9.5px;padding:7px 5px;line-height:1.3}
.po-table td{padding:6px 5px}
.po-table .c-no{width:24px}
.po-table .c-bar{width:92px}
.po-table .c-qty{width:86px}
.po-table .c-disc{width:52px}
.po-table td.bar{font-variant-numeric:tabular-nums;font-size:10px;color:var(--muted)}
.po-table .u{font-size:9px;color:var(--muted);margin-inline-start:3px;font-weight:600}
.po-table .s{font-size:9px;color:var(--muted);margin-top:1px}
.po-table .disc{color:var(--purple-500)}
.po-table .muted{color:var(--muted)}
.po-table tr.pad td{height:24px;border-bottom:1px solid var(--border)}
.po-table tr.sum td{border-top:2px solid var(--ink);background:var(--card2)}

/* ═══ التجميعة جنب بيانات البنك ═══ */
.po-summary{
  display:flex;gap:18px;align-items:flex-start;
  margin-top:12px;flex-wrap:wrap;
}
.po-summary .doc-totals{margin-top:0;margin-inline-start:auto;min-width:260px}
.po-doc .doc-totals .row{padding:5px 0;font-size:12px}
.po-doc .doc-totals .row,.po-doc .doc-table td{color:var(--ink)}
.po-doc .doc-totals .row.net{
  border-top:1px solid var(--border);margin-top:3px;padding-top:8px;
  font-weight:700;
}
.po-doc .doc-totals .row.grand{border-top-color:var(--ink);font-size:16px}

.po-bank{
  flex:1;min-width:240px;max-width:330px;
  border:1px solid var(--border);border-radius:var(--r-md);
  padding:10px 12px;background:var(--card2);
}
.po-bank .bk-h{font-size:11px;font-weight:800;color:var(--royal-blue);margin-bottom:5px}
.po-bank .bk-warn{
  font-size:10px;line-height:1.7;color:var(--ink);font-weight:700;
  padding-bottom:6px;margin-bottom:6px;border-bottom:1px dashed var(--border);
}
.po-bank .bk-t{width:100%;border-collapse:collapse}
.po-bank .bk-t td{padding:2px 0;font-size:10px;color:var(--muted);border:0;vertical-align:top}
.po-bank .bk-t td:last-child{text-align:end;color:var(--ink)}
.po-bank .bk-t td b{font-variant-numeric:tabular-nums;letter-spacing:.2px}

/* ═══ الفوتر: العنوان والتليفون والإيميل بس — سطر واحد في النص ═══ */
.po-foot{justify-content:center;letter-spacing:0}
.po-foot .ft-inline{
  display:flex;justify-content:center;flex-wrap:wrap;gap:4px 18px;
  font-size:10px;font-weight:600;color:var(--muted);
}

/* ═══ متعدد الورقات (١٠/٨): الأمر الكبير بيتقسم — كل جزء ورقة
   A4 لوحده. `po-cont` = ورقة تكملة (مش الأخيرة) بتقفل صفحتها.
   على الشاشة مسافة بين الورقات — نفس إحساس الطباعة المجمعة. */
.po-doc + .po-doc{margin-top:24px}
.po-pageno{font-weight:700}

/* الصاعقة: في نص الورقة، خفيفة */
.po-doc .bolt-mark.po-bolt{
  width:460px;top:34%;
  inset-inline-start:50%;margin-inline-start:-230px;
  opacity:.04;transform:rotate(8deg);
}

@media print{
  /* A4: 297mm − هوامش 12mm×2 = 273mm — الفوتر آخر الورقة والجدول
     بياخد الفراغ. الصف مايتقسمش على صفحتين. */
  @page{size:A4;margin:12mm}
  .po-doc{min-height:273mm}
  .po-doc .doc-head{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .po-doc .bolt-mark.po-bolt{opacity:.035 !important}
  .po-bank,.po-table tr.sum td,.po-table th{
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
  }
  .po-table tr{break-inside:avoid;page-break-inside:avoid}
  .po-summary{break-inside:avoid;page-break-inside:avoid}

  /* ورقة التكملة بتقفل صفحتها — والأخيرة من غير فاصل عشان الطباعة
     المجمعة (po_print_batch) هي اللي بتحكم الفاصل بين أمر وأمر */
  .po-doc.po-cont{break-after:page;page-break-after:always}
  .po-doc + .po-doc{margin-top:0}
}
</style>
