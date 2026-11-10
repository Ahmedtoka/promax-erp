{{--
    ═══ مربع «الضريبة لكل سطر» (قرار المالك ١٠/١١/٢٠٢٦) ═══

    السعر المتخزّن غير شامل دايماً. العرض حاجتين:
      • مقفول: كل سطر بسعره غير الشامل، والضريبة والإجمالي تحت في التجميعة.
      • مفتوح: كل سطر فيه غير شامل | الضريبة | الشامل — عشان فاتورة
        المندوب المطبوعة (شامل) تتطابق سطر بسطر.

    الاستخدام: أي خلية/عنوان عمود ضريبة أو شامل تاخد كلاس `c-vat`، والمستند
    كله جوه عنصر عليه `data-vat-scope`. المربع بيقلب كلاس `vat-on` على الـ
    `<body>` — شاشة وطباعة الاتنين (اللي بتشوفه هو اللي بيتطبع).

    ⚠️ عرض بس: مفيش ولا رقم بيتحسب هنا — القيم كلها من السطر المخزّن
    (`total` و`tax`)، والشامل = مجموعهم. الضريبة عمرها ما بتتحسب مرتين.

    @param bool $vatDefault  يبدأ مفتوح؟ (فاتورة المندوب = أيوه)
--}}
<style>
    body:not(.vat-on) [data-vat-scope] .c-vat{display:none}
    .vat-toggle{display:inline-flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;
        padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);cursor:pointer}
    @media print{.vat-toggle{display:none !important}}
</style>
<label class="vat-toggle" data-noprint title="{{ __('doc.vat_lines_hint') }}">
    <input type="checkbox" id="vatLinesToggle" @checked($vatDefault ?? false)
           onchange="document.body.classList.toggle('vat-on', this.checked)">
    {{ __('doc.vat_lines') }}
</label>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var t = document.getElementById('vatLinesToggle');
        if (t) document.body.classList.toggle('vat-on', t.checked);
    });
</script>
