<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderComboComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'item_type',
        'item_id',
        'item_name_snapshot',
        'qty_per_combo',
        'ordered_combo_qty',
        'total_qty',
        'unit_name_snapshot',
        'cost_snapshot',
        'printer_id_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'qty_per_combo' => 'decimal:4',
            'ordered_combo_qty' => 'decimal:4',
            'total_qty' => 'decimal:4',
            'cost_snapshot' => 'decimal:4',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
