<?php

return [
    /*
     * Where snapshots are written. Outside the application directory, so a
     * deploy that replaces the release folder cannot take the backups with it.
     */
    'directory' => env('BACKUP_DIRECTORY', storage_path('backups')),

    /*
     * Days of local snapshots to keep. These are the on-host copies; an
     * off-host copy is the operator's job and is described in
     * docs/backup-restore.md.
     */
    'keep_days' => env('BACKUP_KEEP_DAYS', 14),

    /*
     * A dump smaller than this is treated as a failure. An empty dump is a
     * failure that looks like success, which is the worst kind to have.
     */
    'minimum_bytes' => env('BACKUP_MINIMUM_BYTES', 1024),

    'timeout_seconds' => env('BACKUP_TIMEOUT_SECONDS', 900),

    /*
     * Hours after which the health page calls the last backup stale. Comfortably
     * more than the daily schedule, so one missed run is a warning rather than
     * noise from a few minutes' drift.
     */
    'stale_after_hours' => env('BACKUP_STALE_AFTER_HOURS', 36),

    /*
     * Set when pg_dump is not on the web user's PATH, which is common.
     */
    'pg_dump_path' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
];
