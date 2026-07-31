<?php

namespace App\Models;

use App\Traits\HasOutlet;



use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IngredientBatch extends Model
{
    use SoftDeletes, HasOutlet;
    protected $outletColumn = 'location_id';


    protected $guarded = [];

    protected $casts = [
        'original_qty' => 'float',
        'usable_qty' => 'float',
        'unit_cost' => 'float',
        'received_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function purchaseItem()
    {
        return $this->belongsTo(PurchaseItem::class);
    }
}
