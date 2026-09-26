<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The role picker already stops at your own role; this refuses a forged
     * value past it -- nobody creates an account that outranks them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = ($data['role'] ?? null) instanceof Role ? $data['role'] : Role::tryFrom((string) ($data['role'] ?? ''));

        abort_if($role === null || ! User::canGrantRole(auth()->user()->role, $role), 403);

        return $data;
    }
}
