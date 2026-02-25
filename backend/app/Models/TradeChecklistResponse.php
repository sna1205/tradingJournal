<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeChecklistResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_id',
        'checklist_id',
        'checklist_item_id',
        'value',
        'is_completed',
        'completed_at',
    ];

    protected $casts = [
        'trade_id' => 'integer',
        'checklist_id' => 'integer',
        'checklist_item_id' => 'integer',
        'value' => 'array',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}
