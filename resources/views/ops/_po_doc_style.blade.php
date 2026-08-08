{{-- ستايل مستند أمر التوريد — مشترك بين الفردي والمجمع.
     ⚠️ لازم `partials._doc_style` يتضمّن قبله. --}}
<style>
/* ═══ أمر التوريد: شبه أحادي اللون + فوتر لاصق + صاعقة في النص ═══ */

/* الفوتر في آخر الورقة — المستند عمود والجسم بياخد الفراغ */
.po-doc{display:flex;flex-direction:column}
.po-doc .doc-body{flex:1}

/* الهيدر: أبيض بدل التدرج — لمسة البراند خط تحته بس */
.po-doc .doc-head{
  background:#fff;color:var(--ink);
  border-bottom:3px solid var(--royal-blue);
  padding:18px 26px 14px;
}
.po-doc .doc-corp{color:var(--muted);opacity:1}
.po-doc .doc-no{color:var(--ink)}
.po-doc .doc-date{color:var(--muted);opacity:1}

/* البيانات القانونية تحت اللوجو */
.po-doc .doc-legal{
  display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:7px;
  font-size:10.5px;color:var(--muted);letter-spacing:.2px;
}
.po-doc .doc-legal b{color:var(--ink);font-weight:700}

/* العنوان الكبير في نص الورقة */
.po-title{
  position:relative;z-index:1;text-align:center;
  font-size:25px;font-weight:900;letter-spacing:-.3px;
  color:var(--royal-blue);margin:20px 0 2px;
}

/* بلوك الأطراف: أبيض ببرواز بدل الخلفية الزرقا */
.po-doc .doc-parties{background:#fff;border:1px solid var(--border)}
.po-doc .doc-parties .k{color:var(--muted)}

/* الأرقام كلها بلون الحبر — مفيش أزرق في الجدول */
.po-doc .doc-table td,
.po-doc .doc-totals .row{color:var(--ink)}
.po-doc .doc-totals .row.grand{border-top-color:var(--ink)}

/* ═══ الجدول: 8 أعمدة على A4 ═══
   ⚠️ الأعمدة الجديدة (خصم + سعر بعد الخصم) ضغطت العرض، فالخط أصغر
   والباركود tabular عشان الأرقام تفضل متحاذية تحت بعض. */
.po-table{font-size:11.5px}
.po-table th{font-size:10px;padding:8px 6px;line-height:1.35}
.po-table td{padding:8px 6px}
.po-table .c-no{width:26px}
.po-table .c-bar{width:96px}
.po-table .c-qty{width:88px}
.po-table .c-disc{width:56px}
.po-table td.bar{font-variant-numeric:tabular-nums;font-size:10.5px;color:var(--muted)}
.po-table .u{font-size:9.5px;color:var(--muted);margin-inline-start:3px;font-weight:600}
.po-table .s{font-size:9.5px;color:var(--muted);margin-top:2px}
.po-table .disc{color:var(--purple-500)}
.po-table .muted{color:var(--muted)}
.po-table tr.sum td{border-top:2px solid var(--ink);background:var(--card2)}

/* ═══ التجميعة جنب بيانات البنك ═══
   ⚠️ البنك أول العنصر عشان يقعد ناحية بداية السطر في الاتجاهين —
   `flex` مع `margin-inline-start:auto` على التجميعة بيضمن ده من غير
   أي قاعدة RTL منفصلة. */
.po-summary{
  display:flex;gap:22px;align-items:flex-start;
  margin-top:18px;flex-wrap:wrap;
}
.po-summary .doc-totals{margin-top:0;margin-inline-start:auto;min-width:270px}
.po-doc .doc-totals .row.net{
  border-top:1px solid var(--border);margin-top:4px;padding-top:9px;
  font-weight:700;color:var(--ink);
}

.po-bank{
  flex:1;min-width:250px;max-width:340px;
  border:1px solid var(--border);border-radius:var(--r-md);
  padding:11px 13px;background:var(--card2);
}
.po-bank.demo{border-color:var(--orange);border-style:dashed}
.po-bank .bk-h{
  font-size:11.5px;font-weight:800;color:var(--royal-blue);
  margin-bottom:6px;letter-spacing:.2px;
}
.po-bank .bk-warn{
  font-size:10.5px;line-height:1.7;color:var(--ink);font-weight:700;
  padding-bottom:7px;margin-bottom:7px;border-bottom:1px dashed var(--border);
}
.po-bank .bk-t{width:100%;border-collapse:collapse}
.po-bank .bk-t td{
  padding:2.5px 0;font-size:10.5px;color:var(--muted);
  border:0;vertical-align:top;
}
.po-bank .bk-t td:last-child{text-align:end;color:var(--ink)}
.po-bank .bk-t td b{font-variant-numeric:tabular-nums;letter-spacing:.2px}
.po-bank .bk-demo{
  margin-top:8px;font-size:10px;font-weight:700;
  color:var(--orange);line-height:1.6;
}

/* ═══ الفوتر: ٣ بلوكات بدل سطرين ═══ */
.po-foot{align-items:flex-start;gap:16px;letter-spacing:0;line-height:1.8}
.po-foot .ft-corp{font-weight:800;color:var(--ink);font-size:10.5px;letter-spacing:.8px;white-space:nowrap}
.po-foot .ft-lines{flex:1;text-align:center;font-size:9.5px;font-weight:500}
.po-foot .ft-inline{display:flex;justify-content:center;flex-wrap:wrap;gap:3px 13px}
.po-foot .ft-ref{font-size:9.5px;white-space:nowrap}

/* الصاعقة: في نص الورقة، أخف، مش متاكلة من الجنب */
.po-doc .bolt-mark.po-bolt{
  width:480px;top:32%;
  inset-inline-start:50%;margin-inline-start:-240px;
  opacity:.04;transform:rotate(8deg);
}

@media print{
  /* ورقة A4 كاملة: 297mm − هوامش 12mm×2 — الفوتر بينزل آخرها */
  .po-doc{min-height:273mm}
  .po-doc .doc-head{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .po-doc .bolt-mark.po-bolt{opacity:.035 !important}

  /* ⚠️ من غير ده البوكس بيطلع أبيض على أبيض والنوت بتضيع */
  .po-bank,.po-table tr.sum td,.po-table th{
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
  }
  /* الصف مايتقسمش على صفحتين */
  .po-table tr{break-inside:avoid;page-break-inside:avoid}
  .po-summary{break-inside:avoid;page-break-inside:avoid}
}
</style>
