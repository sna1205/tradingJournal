<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Killzone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'session_enum',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
