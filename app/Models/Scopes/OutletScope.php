<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\App;

class OutletScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply scope if current_outlet_id is bound in the container (i.e., during API request)
        if (App::has('current_outlet_id')) {
            $outletId = App::make('current_outlet_id');
            // Check what the model uses.
            $column = 'outlet_id';
            if (method_exists($model, 'getOutletColumnName')) {
                $column = $model->getOutletColumnName();
            }
            
            $builder->where($model->getTable() . '.' . $column, $outletId);
        }
    }
}
