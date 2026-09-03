<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['command', 'last_success_at', 'last_failure_at', 'last_failure_message'])]
class CommandRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public static function recordSuccess(string $command): void
    {
        static::updateOrCreate(['command' => $command], ['last_success_at' => now()]);
    }

    public static function recordFailure(string $command, string $message): void
    {
        static::updateOrCreate(['command' => $command], [
            'last_failure_at' => now(),
            'last_failure_message' => mb_substr($message, 0, 255),
        ]);
    }
}
