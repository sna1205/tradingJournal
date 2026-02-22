<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'scope',
        'filters_json',
        'columns_json',
        'is_default',
    ];

    protected $casts = [
        'filters_json' => 'array',
        'columns_json' => 'array',
        'is_default' => 'boolean',
    ];
}
