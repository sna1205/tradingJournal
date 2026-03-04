<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'checklist_id',
        'order_index',
        'title',
        'type',
        'required',
        'category',
        'help_text',
        'config',
        'is_active',
    ];

    protected $casts = [
        'checklist_id' => 'integer',
        'order_index' => 'integer',
        'required' => 'boolean',
        'is_active' => 'boolean',
        'config' => 'array',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TradeChecklistResponse::class);
    }
}
