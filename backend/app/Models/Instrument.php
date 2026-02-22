<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instrument extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'asset_class',
        'base_currency',
        'quote_currency',
        'contract_size',
        'tick_size',
        'tick_value',
        'pip_size',
        'min_lot',
        'lot_step',
        'is_active',
    ];

    protected $casts = [
        'contract_size' => 'decimal:8',
        'tick_size' => 'decimal:10',
        'tick_value' => 'decimal:8',
        'pip_size' => 'decimal:10',
        'min_lot' => 'decimal:4',
        'lot_step' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}

