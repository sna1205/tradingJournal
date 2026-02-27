<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trade extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'account_id',
        'instrument_id',
        'strategy_model_id',
        'setup_id',
        'killzone_id',
        'pair',
        'direction',
        'entry_price',
        'avg_entry_price',
        'stop_loss',
        'take_profit',
        'actual_exit_price',
        'avg_exit_price',
        'lot_size',
        'risk_per_unit',
        'reward_per_unit',
        'monetary_risk',
        'monetary_reward',
        'gross_profit_loss',
        'costs_total',
        'commission',
        'swap',
        'spread_cost',
        'slippage_cost',
        'fx_rate_quote_to_usd',
        'fx_symbol_used',
        'fx_rate_timestamp',
        'profit_loss',
        'rr',
        'r_multiple',
        'realized_r_multiple',
        'risk_percent',
        'account_balance_before_trade',
        'account_balance_after_trade',
        'followed_rules',
        'checklist_incomplete',
        'executed_checklist_id',
        'executed_checklist_version',
        'executed_enforcement_mode',
        'failed_rule_ids',
        'failed_rule_titles',
        'check_evaluated_at',
        'emotion',
        'risk_override_reason',
        'session',
        'session_enum',
        'model',
        'date',
        'notes',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'instrument_id' => 'integer',
        'strategy_model_id' => 'integer',
        'setup_id' => 'integer',
        'killzone_id' => 'integer',
        'entry_price' => 'decimal:6',
        'avg_entry_price' => 'decimal:6',
        'stop_loss' => 'decimal:6',
        'take_profit' => 'decimal:6',
        'actual_exit_price' => 'decimal:6',
        'avg_exit_price' => 'decimal:6',
        'lot_size' => 'decimal:4',
        'risk_per_unit' => 'decimal:6',
        'reward_per_unit' => 'decimal:6',
        'monetary_risk' => 'decimal:6',
        'monetary_reward' => 'decimal:6',
        'gross_profit_loss' => 'decimal:6',
        'costs_total' => 'decimal:6',
        'commission' => 'decimal:6',
        'swap' => 'decimal:6',
        'spread_cost' => 'decimal:6',
        'slippage_cost' => 'decimal:6',
        'fx_rate_quote_to_usd' => 'decimal:10',
        'fx_rate_timestamp' => 'datetime',
        'profit_loss' => 'decimal:2',
        'rr' => 'decimal:2',
        'r_multiple' => 'decimal:4',
        'realized_r_multiple' => 'decimal:4',
        'risk_percent' => 'decimal:4',
        'account_balance_before_trade' => 'decimal:2',
        'account_balance_after_trade' => 'decimal:2',
        'followed_rules' => 'boolean',
        'checklist_incomplete' => 'boolean',
        'executed_checklist_id' => 'integer',
        'executed_checklist_version' => 'integer',
        'executed_enforcement_mode' => 'string',
        'failed_rule_ids' => 'array',
        'failed_rule_titles' => 'array',
        'check_evaluated_at' => 'datetime',
        'session_enum' => 'string',
        'date' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['account_id'] ?? null, fn (Builder $builder, int|string $accountId) => $builder->where('account_id', (int) $accountId))
            ->when($filters['account_ids'] ?? null, function (Builder $builder, array|string $accountIds): void {
                $values = is_array($accountIds)
                    ? $accountIds
                    : explode(',', (string) $accountIds);
                $ids = collect($values)
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values()
                    ->all();

                if (count($ids) > 0) {
                    $builder->whereIn('account_id', $ids);
                }
            })
            ->when($filters['instrument_id'] ?? null, fn (Builder $builder, int|string $instrumentId) => $builder->where('instrument_id', (int) $instrumentId))
            ->when($filters['strategy_model_id'] ?? null, fn (Builder $builder, int|string $strategyModelId) => $builder->where('strategy_model_id', (int) $strategyModelId))
            ->when($filters['setup_id'] ?? null, fn (Builder $builder, int|string $setupId) => $builder->where('setup_id', (int) $setupId))
            ->when($filters['killzone_id'] ?? null, fn (Builder $builder, int|string $killzoneId) => $builder->where('killzone_id', (int) $killzoneId))
            ->when($filters['tag_ids'] ?? null, function (Builder $builder, array|string $tagIds): void {
                $values = is_array($tagIds)
                    ? $tagIds
                    : explode(',', (string) $tagIds);
                $ids = collect($values)
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values()
                    ->all();

                if (count($ids) > 0) {
                    $builder->whereHas('tags', fn (Builder $tagsQuery) => $tagsQuery->whereIn('trade_tags.id', $ids));
                }
            })
            ->when($filters['pair'] ?? null, fn (Builder $builder, string $pair) => $builder->where('pair', 'like', "%{$pair}%"))
            ->when($filters['direction'] ?? null, fn (Builder $builder, string $direction) => $builder->where('direction', $direction))
            ->when($filters['session_enum'] ?? null, fn (Builder $builder, string $sessionEnum) => $builder->where('session_enum', $sessionEnum))
            ->when($filters['session'] ?? null, fn (Builder $builder, string $session) => $builder->where('session', 'like', "%{$session}%"))
            ->when($filters['model'] ?? null, fn (Builder $builder, string $model) => $builder->where('model', 'like', "%{$model}%"))
            ->when($filters['emotion'] ?? null, fn (Builder $builder, string $emotion) => $builder->where('emotion', $emotion))
            ->when($filters['image_context_tag'] ?? null, fn (Builder $builder, string $contextTag) => $builder->whereHas('images', fn (Builder $query) => $query->where('context_tag', $contextTag)))
            ->when($filters['image_timeframe'] ?? null, fn (Builder $builder, string $timeframe) => $builder->whereHas('images', fn (Builder $query) => $query->where('timeframe', $timeframe)))
            ->when(
                array_key_exists('followed_rules', $filters) && $filters['followed_rules'] !== null && $filters['followed_rules'] !== '',
                fn (Builder $builder) => $builder->where('followed_rules', filter_var($filters['followed_rules'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false)
            )
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate('date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate('date', '<=', $dateTo));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function strategyModel(): BelongsTo
    {
        return $this->belongsTo(StrategyModel::class);
    }

    public function setup(): BelongsTo
    {
        return $this->belongsTo(Setup::class);
    }

    public function killzone(): BelongsTo
    {
        return $this->belongsTo(Killzone::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(TradeImage::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(TradeLeg::class)->orderBy('executed_at')->orderBy('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TradeTag::class, 'trade_tag_map')
            ->withTimestamps();
    }

    public function psychology(): HasOne
    {
        return $this->hasOne(TradePsychology::class);
    }

    public function checklistResponses(): HasMany
    {
        return $this->hasMany(TradeChecklistResponse::class);
    }
}
