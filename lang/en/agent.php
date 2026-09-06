<?php

/**
 * Promax Assistant — smart chat strings (2026-09-07).
 */
return [

    // UI
    'title' => 'Promax Assistant',
    'subtitle' => 'Accounting Agent — Read Only',
    'empty_hint' => "Ask me about a client's statement, balance, or receivables aging — e.g. \"What is ikardio gym's balance?\"",
    'placeholder' => 'Type your question…',
    'send' => 'Send',
    'thinking' => 'Working on it…',
    'new_chat' => 'New Chat',
    'close' => 'Close',
    'open_btn_label' => 'Open Promax Assistant',

    // "Open in system" buttons
    'open_client' => 'Open Client Card',
    'open_dashboard' => 'Open Dashboard',

    // Data blocks
    'd_statement' => 'Statement of Account — :name',
    'd_stmt_footer' => 'Showing :shown of :total entries — Debit :debit · Credit :credit',
    'd_aging' => 'Receivables Aging — All Channels',
    'd_aging_ch' => 'Receivables Aging — :channel Channel',
    'd_aging_footer' => 'Total EGP :total across :clients clients',
    'd_candidates' => 'Found more than one client — pick one',
    'c_memo' => 'Memo',
    'c_kind' => 'Kind',
    'c_debit' => 'Debit',
    'c_credit' => 'Credit',
    'c_balance' => 'Balance',
    'c_purchases' => 'Purchases',
    'c_collections' => 'Collections',
    'c_returns' => 'Returns',
    'money' => 'EGP :n',
    'egp' => 'EGP',

    // Errors
    'err_timeout' => 'The assistant took too long — try again, and tell the admin if it keeps happening.',
    'err_api' => 'The assistant cannot answer right now — try again in a bit.',
    'err_generic' => 'Something unexpected went wrong — try again, and tell the admin if it keeps happening.',
    'err_not_configured' => 'The assistant is not set up yet — the admin needs to add the API key.',
    'empty_reply' => "I couldn't come up with an answer — try rephrasing your question.",
];
