<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup destination
    |--------------------------------------------------------------------------
    |
    | Where nightly database dumps are written. This should be a folder that
    | gets copied off the machine on its own — e.g. a OneDrive/Dropbox synced
    | folder, or a mapped network drive. Defaults to a OneDrive folder under
    | the current Windows user's profile; override in .env if that's wrong
    | for this machine.
    |
    */

    'destination' => env(
        'BACKUP_DESTINATION',
        rtrim(getenv('USERPROFILE') ?: sys_get_temp_dir(), '\\/').'\\OneDrive\\portico-backups'
    ),

    /*
    |--------------------------------------------------------------------------
    | Daily backup retention
    |--------------------------------------------------------------------------
    |
    | Daily dumps older than this many days are pruned on each run. The
    | end-of-month copy is kept separately and is never pruned by this.
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | mysqldump path
    |--------------------------------------------------------------------------
    |
    | Some MariaDB/MySQL distributions (e.g. Laravel Herd's bundled MariaDB on
    | Windows) do not put mysqldump.exe on PATH, so this usually needs to be
    | set explicitly in .env — see README "Backups".
    |
    */

    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),

];
