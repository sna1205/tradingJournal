<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeImage extends Model
{
    use HasFactory;

    public $timestamps = false;
    public const UPDATED_AT = null;

    protected $fillable = [
        'trade_id',
        'image_url',
        'thumbnail_url',
        'file_size',
        'file_type',
        'sort_order',
        'created_at',
    ];

    protected $casts = [
        'trade_id' => 'integer',
        'file_size' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}

