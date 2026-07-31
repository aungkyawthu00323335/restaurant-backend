<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GiftCard extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'card_number',
        'number',
        'description',
        'initial_value',
        'expire_date',
    ];

    protected function casts(): array
    {
        return [
            'initial_value' => 'decimal:2',
            'expire_date' => 'date',
        ];
    }
}
