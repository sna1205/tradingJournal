<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradePsychology extends Model
{
    use HasFactory;

    protected $table = 'trade_psychology';

    protected $fillable = [
        'trade_id',
        'pre_emotion',
        'post_emotion',
        'confidence_score',
        'stress_score',
        'sleep_hours',
        'impulse_flag',
        'fomo_flag',
        'revenge_flag',
        'notes',
    ];

    protected $casts = [
        'trade_id' => 'integer',
        'confidence_score' => 'integer',
        'stress_score' => 'integer',
        'sleep_hours' => 'decimal:2',
        'impulse_flag' => 'boolean',
        'fomo_flag' => 'boolean',
        'revenge_flag' => 'boolean',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
