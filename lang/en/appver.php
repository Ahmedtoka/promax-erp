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

];
