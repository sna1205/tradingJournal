<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MissedTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pair',
        'model',
        'reason',
        'date',
        'notes',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'date' => 'datetime',
    ];

    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['pair'] ?? null, fn (Builder $builder, string $pair) => $builder->where('pair', 'like', "%{$pair}%"))
            ->when($filters['model'] ?? null, fn (Builder $builder, string $model) => $builder->where('model', 'like', "%{$model}%"))
            ->when($filters['reason'] ?? null, fn (Builder $builder, string $reason) => $builder->where('reason', 'like', "%{$reason}%"))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate('date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate('date', '<=', $dateTo));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(MissedTradeImage::class);
    }
}
