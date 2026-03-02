<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FxRateSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'snapshot_date',
        'rate',
        'rate_updated_at',
        'provider',
        'source',
        'bid',
        'ask',
        'mid',
        'bid_provenance',
        'ask_provenance',
        'mid_provenance',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'rate' => 'decimal:10',
        'bid' => 'decimal:10',
        'ask' => 'decimal:10',
        'mid' => 'decimal:10',
        'rate_updated_at' => 'datetime',
    ];
}
