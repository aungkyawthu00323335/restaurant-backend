<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboMenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'combo_menu_id',
        'item_type',
        'item_id',
        'item_name_snapshot',
        'qty',
        'unit_id',
        'unit_name_snapshot',
        'cost_per_unit_snapshot',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'cost_per_unit_snapshot' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function comboMenu(): BelongsTo
    {
        return $this->belongsTo(ComboMenu::class);
    }
}
