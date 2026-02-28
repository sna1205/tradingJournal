<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'provider',
        'source',
        'bid',
        'ask',
        'mid',
        'bid_provenance',
        'ask_provenance',
        'mid_provenance',
        'rate_updated_at',
    ];

    protected $casts = [
        'rate' => 'decimal:10',
        'bid' => 'decimal:10',
        'ask' => 'decimal:10',
        'mid' => 'decimal:10',
        'rate_updated_at' => 'datetime',
    ];
}
