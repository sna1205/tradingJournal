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
        'rate_updated_at',
    ];

    protected $casts = [
        'rate' => 'decimal:10',
        'rate_updated_at' => 'datetime',
    ];
}
