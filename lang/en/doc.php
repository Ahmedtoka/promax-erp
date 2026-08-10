<?php

/**
 * Printed-document strings — sales order, invoice, return note.
 *
 * ⚠️ Deliberately separate from `ops`: these strings are printed and
 * handed to a third party, and some of them are legal (tax card,
 * commercial register, the bank-transfer note). Keeping them in one
 * file means they get reviewed once instead of across five files.
 */
return [

    // ═══════════ Company details ═══════════
    'tax_id' => 'Tax Card',
    'cr' => 'Commercial Register',
    'email' => 'Email',

    // ═══════════ Table ═══════════
    'line_no' => '#',
    'unit_price' => 'Price',
    'discount_pct' => 'Discount',
    'price_after_discount' => 'Price After Discount',

    // ═══════════ Totals ═══════════
    'gross_before_discount' => 'Total before discount',
    'discount_value' => 'Discount',
    'net_before_tax' => 'Total before tax',
    'vat_on' => 'VAT :rate% on :base',
    'exempt_base' => 'Tax-exempt items',
    'total_with_tax' => 'Total including tax',

    // ═══════════ Bank details ═══════════
    'bank_details' => 'Bank Transfer Details',
    'bank_note' => 'Please deposit all due amounts to the listed account number only.',
    'bank_name' => 'Bank',
    'bank_branch' => 'Branch',
    'bank_account_name' => 'Account name',
    'bank_account_no' => 'Account no.',
    'bank_iban' => 'IBAN',
    'bank_swift' => 'SWIFT',
    'bank_demo' => 'Demo bank details — change them in Tax & Company Settings before handing this document to any client.',

    // ═══ الفاتورة المؤقتة (2026-08-09) ═══
    'proforma' => 'Proforma Invoice',
    'date' => 'Date',
    'time' => 'Time',
    'rep_name' => 'Rep Name',
    'made_by' => 'Order created by',
    'address' => 'Address',

    // ═══ Proforma multi-page split (2026-08-10) ═══
    'page_of' => 'Page :p of :t',
];
