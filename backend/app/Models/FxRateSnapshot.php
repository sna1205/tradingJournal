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
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'rate' => 'decimal:10',
    ];
}
