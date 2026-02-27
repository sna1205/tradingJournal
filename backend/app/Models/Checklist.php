<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'account_id',
        'strategy_model_id',
        'name',
        'revision',
        'scope',
        'enforcement_mode',
        'is_active',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'account_id' => 'integer',
        'strategy_model_id' => 'integer',
        'revision' => 'integer',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function strategyModel(): BelongsTo
    {
        return $this->belongsTo(StrategyModel::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('order_index')->orderBy('id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TradeChecklistResponse::class);
    }
}
