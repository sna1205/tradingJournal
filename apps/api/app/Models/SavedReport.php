<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'scope',
        'filters_json',
        'columns_json',
        'is_default',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'filters_json' => 'array',
        'columns_json' => 'array',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
