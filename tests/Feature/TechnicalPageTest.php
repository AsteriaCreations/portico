<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Technical;
use App\Models\CommandRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('an admin can access the technical page and sees its widgets', function () {
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    $this->actingAs($admin)
        ->get('/admin/technical')
        ->assertSuccessful()
        ->assertSee('Database backup')
        ->assertSee('Comp reward vouchers');
});

test('a manager is forbidden from the technical page', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);

    $this->actingAs($manager)->get('/admin/technical')->assertForbidden();
});

test('an admin can run the backup now, and it records a CommandRun success', function () {
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($admin);

    $backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-technical-page-test-'.uniqid();
    config([
        'backup.destination' => $backupDir,
        'backup.mysqldump_path' => 'mysqldump',
    ]);
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    try {
        Livewire::test(Technical::class)
            ->callAction('runBackupDatabase')
            ->assertHasNoActionErrors();
    } finally {
        File::deleteDirectory($backupDir);
    }

    $run = CommandRun::firstWhere('command', 'backup:database');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});

test('an admin can run the comp-reward grant now, and it records a CommandRun success', function () {
    User::factory()->create(['email' => 'system@portico.internal', 'role' => Role::Admin, 'active' => false]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(Technical::class)
        ->callAction('runVoucherGrant')
        ->assertHasNoActionErrors();

    $run = CommandRun::firstWhere('command', 'vouchers:grant-comp-rewards');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});
