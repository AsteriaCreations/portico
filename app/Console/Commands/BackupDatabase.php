<?php

namespace App\Console\Commands;

use App\Models\CommandRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

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

        File::ensureDirectoryExists($dailyDir);
        File::ensureDirectoryExists($monthlyDir);

        $today = now();
        $dailyPath = $dailyDir.DIRECTORY_SEPARATOR."portico-{$today->toDateString()}.sql.gz";

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

        $gzip = gzopen($dailyPath, 'w9');
        gzwrite($gzip, $result->output());
        gzclose($gzip);

        $this->info("Wrote {$dailyPath}");

        if ($today->isLastOfMonth()) {
            $monthlyPath = $monthlyDir.DIRECTORY_SEPARATOR."portico-{$today->format('Y-m')}-monthly.sql.gz";
            File::copy($dailyPath, $monthlyPath);
            $this->info("Wrote {$monthlyPath}");
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
}
