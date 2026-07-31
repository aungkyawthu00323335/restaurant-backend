<?php

namespace App\Models;

use App\Traits\HasOutlet;



use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\LogsActivity;

class Purchase extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasOutlet;
    protected $outletColumn = 'location_id';


    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'date',
        'subtotal' => 'float',
        'discount' => 'float',
        'shipping_charge' => 'float',
        'grand_total' => 'float',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
