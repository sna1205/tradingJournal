<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountRiskPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'max_risk_per_trade_pct',
        'max_daily_loss_pct',
        'max_total_drawdown_pct',
        'max_open_risk_pct',
        'enforce_hard_limits',
        'allow_override',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'max_risk_per_trade_pct' => 'decimal:4',
        'max_daily_loss_pct' => 'decimal:4',
        'max_total_drawdown_pct' => 'decimal:4',
        'max_open_risk_pct' => 'decimal:4',
        'enforce_hard_limits' => 'boolean',
        'allow_override' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

