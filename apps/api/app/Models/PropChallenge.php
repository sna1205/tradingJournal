<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'provider',
        'phase',
        'starting_balance',
        'profit_target_pct',
        'max_daily_loss_pct',
        'max_total_drawdown_pct',
        'min_trading_days',
        'start_date',
        'status',
        'passed_at',
        'failed_at',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'starting_balance' => 'decimal:2',
        'profit_target_pct' => 'decimal:4',
        'max_daily_loss_pct' => 'decimal:4',
        'max_total_drawdown_pct' => 'decimal:4',
        'min_trading_days' => 'integer',
        'start_date' => 'date',
        'passed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

