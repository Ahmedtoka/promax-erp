<?php

return [

    'page' => 'Leads',
    'page_sub' => 'Shops we want that we have not opened yet',
    'lead' => 'Lead',
    'leads' => 'Leads',
    'new_lead' => 'Add lead',
    'edit_lead' => 'Edit lead',

    // ═══ Statuses ═══
    'status_new' => 'New',
    'status_contacted' => 'Contacted',
    'status_visited' => 'Visited',
    'status_negotiating' => 'Negotiating',
    'status_won' => 'Became a client',
    'status_lost' => 'Lost',

    // ═══ Sources ═══
    'source' => 'Source',
    'source_sheet' => 'Spreadsheet',
    'source_gmaps' => 'Google Maps',
    'source_facebook' => 'Facebook / Instagram',
    'source_field' => 'Found in the field',
    'source_referral' => 'Referral',
    'source_inbound' => 'They contacted us',
    'source_other' => 'Other source',

    // ═══ Fields ═══
    'contact_name' => 'Contact person',
    'expected_monthly' => 'Expected monthly',
    'next_action' => 'Next step',
    'lost_reason' => 'Reason lost',
    'assigned_to' => 'Assigned rep',
    'converted_to' => 'Converted to',
    'pipeline' => 'Pipeline value',
    'open_leads' => 'Open leads',
    'won_leads' => 'Converted to clients',
    'lost_leads' => 'Lost',
    'overdue' => 'Overdue',

    // ═══ Buttons ═══
    'convert' => 'Convert to client',
    'convert_confirm' => 'A new client will be created with all of this lead details. Go ahead?',

    // ═══ Messages ═══
    'added' => 'Lead added',
    'updated' => 'Lead updated',
    'converted' => 'Converted to client :code',
    'already_converted' => 'This lead was already converted to :client',
    'converted_readonly' => 'This lead is now a client — edit it from the client card',
    'name_taken' => 'A client with the same name already exists (:code). Check it first.',
    'not_found' => 'This lead does not exist',
    'win_by_convert' => 'The "Became a client" status is set by the convert button, not from the list',
    'none' => 'No leads',
    'overdue_note' => 'The next step is past its date',
    'code_clash' => 'The code was taken at the same moment — try again',

    // ═══ Sourcing (2026-08-13) ═══
    'score' => 'Score',
    'score_hint' => 'How strong this lead looks — activity, size and rating. It is a ranking, not money.',
    'rating' => 'Rating',
    'reviews' => 'Reviews',
    'category_raw' => 'Listed activity',
    'website' => 'Website',
    'top_score' => 'Strong leads',
    'top_score_note' => 'Score 70 and above',
    'sort_score' => 'Strongest first',
    'sort_recent' => 'Newest first',
    'all_sources' => 'Any source',

    // Import summary notes
    'dup_client_note' => '":name" skipped — already a client (:code)',
    'skip_dup_clients' => ':n rows skipped: already registered as clients',
    'skip_dup_leads' => ':n rows skipped: already in the leads list',
    'skip_dup_sheet' => ':n rows skipped: repeated inside the uploaded file',
    'skip_closed' => ':n rows skipped: the place is marked closed',
    'skip_off_target' => ':n rows skipped: the activity is not one we sell to',
];
