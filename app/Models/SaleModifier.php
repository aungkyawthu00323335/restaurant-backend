<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleModifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_item_id', 'modifier_group_name_snapshot', 'modifier_item_name_snapshot', 'price_adjustment_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment_snapshot' => 'decimal:4',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
