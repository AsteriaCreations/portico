<?php

namespace App\Console\Commands;

use App\Models\CommandRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

#[Signature('backup:database')]
#[Description('Dump the database to the configured backup folder and prune old daily dumps.')]
class BackupDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = config('database.connections.mariadb');

        $destination = config('backup.destination');
        $dailyDir = $destination.DIRECTORY_SEPARATOR.'daily';
        $monthlyDir = $destination.DIRECTORY_SEPARATOR.'monthly';

        // A folder this process can't create or write to (e.g. another
        // Windows account's OneDrive, when the web server runs as
        // LocalSystem) must fail with a reason, not crash the page that ran it.
        try {
            File::ensureDirectoryExists($dailyDir);
            File::ensureDirectoryExists($monthlyDir);
        } catch (Throwable $exception) {
            return $this->failWith("Can't create the backup folder {$dailyDir} as {$this->runningAs()}: {$exception->getMessage()}");
        }

        if (! is_writable($dailyDir)) {
            return $this->failWith("Can't write to the backup folder {$dailyDir} as {$this->runningAs()}.");
        }

        $today = now();
        $prefix = config('backup.prefix');
        $dailyPath = $dailyDir.DIRECTORY_SEPARATOR."{$prefix}-{$today->toDateString()}.sql.gz";

        $result = Process::env(['MYSQL_PWD' => $connection['password']])
            ->timeout(300)
            ->run([
                config('backup.mysqldump_path'),
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--user='.$connection['username'],
                '--single-transaction',
                '--routines',
                '--triggers',
                $connection['database'],
            ]);

        if ($result->failed()) {
            $this->error('mysqldump failed: '.$result->errorOutput());
            CommandRun::recordFailure('backup:database', $result->errorOutput());

            return self::FAILURE;
        }

        try {
            $gzip = gzopen($dailyPath, 'w9');
            if ($gzip === false) {
                return $this->failWith("Can't write {$dailyPath} as {$this->runningAs()}.");
            }
            gzwrite($gzip, $result->output());
            gzclose($gzip);

            $this->info("Wrote {$dailyPath}");

            if ($today->isLastOfMonth()) {
                $monthlyPath = $monthlyDir.DIRECTORY_SEPARATOR."{$prefix}-{$today->format('Y-m')}-monthly.sql.gz";
                File::copy($dailyPath, $monthlyPath);
                $this->info("Wrote {$monthlyPath}");
            }
        } catch (Throwable $exception) {
            return $this->failWith("Can't write the backup into {$destination} as {$this->runningAs()}: {$exception->getMessage()}");
        }

        $cutoff = $today->clone()->subDays(config('backup.retention_days'));

        foreach (File::files($dailyDir) as $file) {
            if ($file->getMTime() < $cutoff->timestamp) {
                File::delete($file->getPathname());
                $this->info("Pruned {$file->getFilename()}");
            }
        }

        CommandRun::recordSuccess('backup:database');

        return self::SUCCESS;
    }

    /**
     * Reports a failure the way a mysqldump error already is: on the console
     * (and so in the Technical page's "Backup failed" notification) and on
     * the Scheduled Jobs panel.
     */
    private function failWith(string $message): int
    {
        $this->error($message);
        CommandRun::recordFailure('backup:database', $message);

        return self::FAILURE;
    }

    /**
     * The OS account this process runs as -- on a Windows box, typically the
     * Scheduled Task's account for the nightly run but LocalSystem (shown as
     * MACHINE$) when the Technical page runs it inside the web server, which
     * is usually why one works and the other doesn't.
     */
    private function runningAs(): string
    {
        $account = getenv('USERNAME') ?: (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null);

        return $account ? "account {$account}" : 'this account';
    }
}
