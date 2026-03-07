<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeLeg extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_id',
        'leg_type',
        'price',
        'quantity_lots',
        'executed_at',
        'fees',
        'notes',
    ];

    protected $casts = [
        'trade_id' => 'integer',
        'price' => 'decimal:6',
        'quantity_lots' => 'decimal:4',
        'executed_at' => 'datetime',
        'fees' => 'decimal:6',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
