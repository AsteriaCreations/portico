<?php

namespace App\Console\Commands;

use App\Models\CommandRun;
use App\Services\OperationalDataReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('data:reset {--force : Skip the typed confirmation (the Technical page action passes this after its own)}')]
#[Description('Back up the database, then delete every member, event and their history — user accounts and configuration are kept.')]
class ResetOperationalData extends Command
{
    /** What an operator has to type to confirm, on the console or the Technical page. */
    public const CONFIRMATION_PHRASE = 'RESET';

    /**
     * Execute the console command.
     */
    public function handle(OperationalDataReset $reset): int
    {
        if (! $this->option('force')) {
            $this->warn('This permanently deletes every member, event, attendance row, subscription, voucher and register shift.');
            $this->warn('User accounts (sign-in credentials) and all configuration are kept.');

            if ($this->ask('Type '.self::CONFIRMATION_PHRASE.' to continue') !== self::CONFIRMATION_PHRASE) {
                $this->error('Not confirmed — nothing was deleted.');

                return self::FAILURE;
            }
        }

        // A reset is only as safe as the backup in front of it: no dump, no wipe.
        if ($this->call('backup:database') !== self::SUCCESS) {
            $this->error('Backup failed — nothing was deleted.');
            CommandRun::recordFailure('data:reset', 'Backup failed; reset aborted.');

            return self::FAILURE;
        }

        $deleted = $reset->reset();

        $this->table(['Table', 'Rows deleted'], collect($deleted)->map(fn (int $count, string $table): array => [$table, $count])->values()->all());

        Log::warning('data:reset cleared operational data', [
            'user_id' => auth()->id(),
            'deleted' => $deleted,
        ]);
        CommandRun::recordSuccess('data:reset');

        $this->info('Reset complete.');

        return self::SUCCESS;
    }
}
