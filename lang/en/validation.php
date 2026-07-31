<?php

/**
 * Validation messages — English.
 *
 * ⚠️ The `attributes` map is the part that matters: it turns a column
 * name into the label the user actually sees on screen. Without it the
 * message names `manager_id`, and whoever is entering the data goes
 * looking for a field by that name and never finds it.
 */
return [

    'required' => 'The :attribute is required.',
    'required_if' => 'The :attribute is required when :other is :value.',
    'required_with' => 'The :attribute is required with :values.',
    'required_unless' => 'The :attribute is required unless :other is :values.',
    'filled' => 'The :attribute cannot be empty.',

    'string' => 'The :attribute must be text.',
    'numeric' => 'The :attribute must be a number.',
    'integer' => 'The :attribute must be a whole number.',
    'boolean' => 'The :attribute must be yes or no.',
    'array' => 'The :attribute must be a list.',
    'date' => 'The :attribute must be a valid date.',
    'email' => 'The :attribute must be a valid email address.',
    'url' => 'The :attribute must be a valid link.',
    'json' => 'The :attribute must be valid JSON.',

    'in' => 'The selected :attribute is not on the list.',
    'not_in' => 'The selected :attribute is not allowed.',
    'exists' => 'The selected :attribute does not exist.',
    'unique' => 'That :attribute is already taken.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'same' => 'The :attribute and :other must match.',
    'different' => 'The :attribute and :other must be different.',

    'after' => 'The :attribute must be after :date.',
    'after_or_equal' => 'The :attribute must be :date or later.',
    'before' => 'The :attribute must be before :date.',
    'before_or_equal' => 'The :attribute must be :date or earlier.',

    'regex' => 'The :attribute format is not valid.',

    'file' => 'The :attribute must be a file.',
    'image' => 'The :attribute must be an image.',
    'mimes' => 'The :attribute must be one of: :values.',
    'uploaded' => 'The :attribute failed to upload — the file may be too large.',

    // ⚠️ Laravel picks the variant by value type. All four must exist,
    // or the message falls back to the raw key for the missing type.
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'file' => 'The :attribute may not be larger than :max kilobytes.',
        'string' => 'The :attribute may not be longer than :max characters.',
        'array' => 'The :attribute may not have more than :max items.',
    ],
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'file' => 'The :attribute must be at least :min kilobytes.',
        'string' => 'The :attribute must be at least :min characters.',
        'array' => 'The :attribute must have at least :min items.',
    ],
    'between' => [
        'numeric' => 'The :attribute must be between :min and :max.',
        'file' => 'The :attribute must be between :min and :max kilobytes.',
        'string' => 'The :attribute must be between :min and :max characters.',
        'array' => 'The :attribute must have between :min and :max items.',
    ],

    'custom' => [
        'location_url' => [
            'regex' => 'The location link must start with http or https.',
        ],
        'clause.*.value' => [
            'required_if' => 'You ticked this clause — enter its value or untick it.',
        ],
    ],

    'attributes' => [
        // ─── Client details ───
        'name' => 'client name',
        'name_en' => 'name in English',
        'phone' => 'phone',
        'governorate' => 'governorate',
        'zone_id' => 'zone',
        'address' => 'address',
        'location_url' => 'location link',
        'channel_id' => 'channel',
        'sub_channel' => 'key account segment',
        'branch_id' => 'branch',
        'group_id' => 'chain',
        'manager_id' => 'account manager',
        'rep_id' => 'field rep',
        'lat' => 'latitude',
        'lng' => 'longitude',
        'contacts' => 'contacts',
        'contacts.*.name' => 'contact name',
        'contacts.*.role' => 'contact role',
        'contacts.*.phone' => 'contact phone',
        'notes' => 'notes',
        'category' => 'category',

        // ─── Pricing & contract ───
        'price_list' => 'price list',
        'discount' => 'custom discount',
        'has_contract' => 'has a contract',
        'contract_type' => 'contract type',
        'contract_duration' => 'contract term',
        'contract_payment_days' => 'payment days',
        'contract_payment_days_from' => 'how the days are counted',
        'contract_starts_at' => 'contract start date',
        'contract_ends_at' => 'contract end date',
        'contract_note' => 'contract notes',
        'contract_clauses' => 'contract clauses',
        'contract_clauses.*' => 'contract clause',
        'contract_file' => 'contract file',
        'clause' => 'discount clauses',
        'clause.invoice_discount.value' => 'invoice discount',
        'clause.quarterly_rebate.value' => 'quarterly rebate',
        'clause.annual_rebate.value' => 'annual rebate',
        'clause.collection_fee.value' => 'collection discount',
        'clause.withholding.value' => 'retention',
        'clause.shelf_rent.value' => 'shelf rent',
        'clause.magazine.value' => 'magazines & advertising',
        'clause.listing_fee.value' => 'listing fee',
        'clause.opening_fee.value' => 'branch opening support',

        // ─── Tax ───
        'taxable' => 'taxable',
        'tax_rate' => 'tax rate',
        'tax_cycle' => 'filing cycle',
        'tax_id' => 'tax ID',
        'eta_type' => 'tax authority recipient type',

        // ─── Elsewhere in the system ───
        'code' => 'code',
        'unit' => 'unit',
        'unit_en' => 'unit in English',
        'family' => 'product family',
        'cost' => 'cost',
        'price_old' => 'old price',
        'price_new' => 'new price',
        'qty' => 'quantity',
        'barcode' => 'barcode',
        'amount' => 'amount',
        'date' => 'date',
        'memo' => 'description',
        'plate' => 'plate number',
        'kind' => 'type',
        'kind_en' => 'type in English',
        'email' => 'email',
        'password' => 'password',
        'chain' => 'chain',
        'chain_en' => 'chain in English',
        'label' => 'clause text',
        'label_en' => 'clause text in English',
        'pct' => 'percentage',
        'basis' => 'timing',
    ],
];
