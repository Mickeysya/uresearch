<?php

return [

    'attendance' => [

        // Which AttendanceSource `attendance:sync` pulls from. One of:
        // inbox, api, simulated. See Support\AttendanceSourceFactory.
        'source' => env('NUREEN_ATTENDANCE_SOURCE', 'inbox'),

        // Where a Power Automate export (or anything standing in for one)
        // drops files for InboxFolderSource. Processed/failed files move
        // into subfolders here, never deleted.
        'inbox_path' => storage_path('app/attendance-inbox'),

        // Shared secret AttendanceIngestController checks the X-Api-Key
        // header against. Null means the endpoint refuses every request --
        // there is no "accept anything" fallback for a credential.
        'ingest_key' => env('NUREEN_ATTENDANCE_INGEST_KEY'),

    ],

];
