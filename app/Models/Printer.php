<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Printer extends Model
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
                $table = $query->getModel()->getTable();
                $query->where(function ($q) use ($table, $outletId): void {
                    $q->where($table.'.location_id', $outletId)
                        ->orWhereNull($table.'.location_id');
                });
            }
        });
    }

    protected $fillable = [
        'name',
        'location_id',
        'ip_address',
        'port',
        'paper_size',
        'copies',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'port' => 'integer',
            'copies' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function foodMenus(): HasMany
    {
        return $this->hasMany(FoodMenu::class);
    }
}
