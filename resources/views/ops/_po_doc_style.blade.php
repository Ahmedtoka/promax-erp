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
}
</style>
