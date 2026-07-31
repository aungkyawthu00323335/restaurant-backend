<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'description',
        'value',
        'type',
        'valid_from',
        'valid_until',
        'min_order_amount',
        'max_usage_per_customer',
        'total_usage_limit',
        'is_active',
        'number',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'min_order_amount' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
