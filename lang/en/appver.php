<?php

// App version — update control screen (2026-08-07)

return [

    'title' => 'App Version',
    'hint' => 'Control updates for the field app',
    'saved' => 'Version saved',

    'latest' => 'Latest available version',
    'latest_hint' => 'Reps see "update available" and can dismiss it and keep working',

    'minimum' => 'Minimum allowed version',
    'minimum_hint' => '⚠️ Anything below this is locked out — use only for forced updates',
    'min_above_latest' => 'Minimum cannot be higher than the latest version — that locks every device onto an update that does not exist',

    'note' => 'Update message',
    'note_ph' => 'e.g. Fixed sales orders + new notifications screen',
    'note_hint' => 'Shown to the rep on the update screen — tell them what changed',

    'apk' => 'Install file (APK)',
    'apk_on_server' => 'File on server · :size MB · uploaded :at',
    'apk_missing' => 'No APK on the server — reps will not be able to update',
    'apk_upload' => 'Upload a new build',
    'apk_hint' => 'Replaces the current file immediately. Build with flutter build apk --release and upload from build/app/outputs/flutter-apk/',
    'download' => 'Download current build',

    'devices' => 'Devices and versions',
    'devices_hint' => 'Version per registered device — shows who actually got the update',
    'installed' => 'Installed version',
    'count' => 'Devices',
    'up_to_date' => 'Up to date',
    'outdated' => 'Outdated',
    'unknown' => 'Unknown',
    'no_devices' => 'No registered devices yet',


    // Chunked upload (Aug 11, 2026)
    'upload_now' => 'Upload file',
    'pick_file_first' => 'Pick the APK file first',
    'chunk_of' => 'Uploading… part :x of :y',
    'upload_done' => 'Fully uploaded - refreshing…',
    'upload_failed' => 'Upload failed',
    'retrying' => 'Connection hiccup - retrying',
    'chunk_out_of_order' => 'Upload got scrambled - start over.',
    'not_an_apk' => 'This file is not an APK - make sure you picked the right one.',
    'one_button_hint' => 'Press Save - it uploads the file with the progress bar, then saves the versions and refreshes automatically.',
];
