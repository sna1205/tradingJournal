<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'pair',
        'direction',
        'entry_price',
        'stop_loss',
        'take_profit',
        'lot_size',
        'profit_loss',
        'rr',
        'session',
        'model',
        'date',
        'notes',
    ];

    protected $casts = [
        'entry_price' => 'decimal:6',
        'stop_loss' => 'decimal:6',
        'take_profit' => 'decimal:6',
        'lot_size' => 'decimal:4',
        'profit_loss' => 'decimal:2',
        'rr' => 'decimal:2',
        'date' => 'datetime',
    ];

    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['pair'] ?? null, fn (Builder $builder, string $pair) => $builder->where('pair', 'like', "%{$pair}%"))
            ->when($filters['direction'] ?? null, fn (Builder $builder, string $direction) => $builder->where('direction', $direction))
            ->when($filters['session'] ?? null, fn (Builder $builder, string $session) => $builder->where('session', 'like', "%{$session}%"))
            ->when($filters['model'] ?? null, fn (Builder $builder, string $model) => $builder->where('model', 'like', "%{$model}%"))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate('date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate('date', '<=', $dateTo));
    }
}
