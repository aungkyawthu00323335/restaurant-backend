<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope('assignedOutletOnly', function ($query): void {
            if (! \Illuminate\Support\Facades\App::bound('current_outlet_id')) {
                return;
            }

            $user = auth()->user();
            if ($user === null || $user->isSuperAdmin()) {
                return;
            }

            $outletId = (int) \Illuminate\Support\Facades\App::make('current_outlet_id');
            if ($outletId > 0) {
                $query->where($query->getModel()->getTable().'.id', $outletId);
            }
        });
    }

    protected $fillable = [
        'name',
        'number',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'opening_time',
        'closing_time',
        'tax_identification_number',
        'image_url',
        'is_head_office',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function foodMenus(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(FoodMenu::class, 'location_food_menu')
            ->withPivot('dine_in_price', 'take_away_price', 'delivery_price', 'is_active')
            ->withTimestamps();
    }

    public function comboMenus(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ComboMenu::class, 'location_combo_menu')
            ->withPivot('dine_in_price', 'take_away_price', 'delivery_price', 'is_active')
            ->withTimestamps();
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'location_product')
            ->withPivot('sell_price_per_unit', 'is_active')
            ->withTimestamps();
    }
}
