<?php

namespace App\Models;

use Database\Factories\RegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'sort_order', 'active'])]
class Register extends Model
{
    /** @use HasFactory<RegisterFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(RegisterShift::class);
    }
}
