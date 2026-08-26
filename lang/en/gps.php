<?php

// Van GPS trackers — iTrack (Aug 26, 2026)
return [
    'title' => 'Van GPS Trackers',

    // Credentials card
    'account' => 'Platform account',
    'password' => 'Password',
    'save_creds' => 'Save account',
    'sync_btn' => 'Pull devices from platform',
    'poll_btn' => 'Refresh positions now',
    'token_state' => 'Connection',
    'token_ok' => 'Connected ✓',
    'token_none' => 'Not connected',
    'last_error' => 'Last error',
    'h_creds' => 'The iTrack platform account the devices are registered under — the password is stored hashed, and positions refresh automatically every minute. "Pull devices" fetches any newly installed tracker.',

    // Table
    'empty' => 'No devices yet — set the account then press "Pull devices from platform".',
    'plate' => 'Plate',
    'linked_user' => 'Linked rep',
    'last_signal' => 'Last signal',
    'state' => 'State',
    'speed' => 'Speed',
    'today_km' => 'Today km',
    'save_links' => 'Save links',
    'h_table' => 'Link each device to its van owner — the van then shows next to the rep on Tracking and the Live board, with an alert when it drifts away from his phone.',

    // Platform states
    'st_online' => 'Online',
    'st_offline' => 'Offline',
    'st_expired' => 'Expired',
    'st_blocked' => 'Blocked',
    'st_never' => 'Never connected',
    'st_unknown' => '—',
    'acc_on' => 'Engine on',

    // Operation messages
    'creds_ok' => 'Account saved and platform connection works ✓',
    'creds_fail' => 'Account saved but connection failed: :e',
    'sync_ok' => 'Pulled :a new devices, updated :u',
    'sync_fail' => 'Device pull failed: :e',
    'poll_ok' => 'Updated :n devices',
    'poll_fail' => 'Refresh failed: :e',
    'saved' => 'Saved links for :n devices',

    // Maps
    'vans_layer' => 'Vans',
    'van_word' => 'Van',
    'van_far' => 'Van is :km km away from the rep',
];
