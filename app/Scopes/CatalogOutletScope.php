<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class CatalogOutletScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $outletId = app()->bound('active_outlet_id')
            ? app('active_outlet_id')
            : (app()->bound('current_outlet_id') ? app('current_outlet_id') : null);

        if (App::runningInConsole() && ! $outletId) {
            return;
        }

        if ($outletId) {
            $table = $model->getTable();

            if ($table === 'food_menus') {
                $builder->leftJoin('location_food_menu', function ($join) use ($model, $outletId) {
                    $join->on('location_food_menu.food_menu_id', '=', $model->getTable() . '.id')
                         ->where('location_food_menu.location_id', '=', $outletId);
                })->where(function ($q) {
                    $q->whereNull('location_food_menu.id')
                      ->orWhere('location_food_menu.is_active', '=', true);
                })->select([
                    $model->getTable() . '.*',
                    DB::raw('COALESCE(location_food_menu.dine_in_price, food_menus.dine_in_price) as dine_in_price'),
                    DB::raw('COALESCE(location_food_menu.take_away_price, food_menus.take_away_price) as take_away_price'),
                    DB::raw('COALESCE(location_food_menu.delivery_price, food_menus.delivery_price) as delivery_price'),
                ]);
            } elseif ($table === 'combo_menus') {
                $builder->leftJoin('location_combo_menu', function ($join) use ($model, $outletId) {
                    $join->on('location_combo_menu.combo_menu_id', '=', $model->getTable() . '.id')
                         ->where('location_combo_menu.location_id', '=', $outletId);
                })->where(function ($q) {
                    $q->whereNull('location_combo_menu.id')
                      ->orWhere('location_combo_menu.is_active', '=', true);
                })->select([
                    $model->getTable() . '.*',
                    DB::raw('COALESCE(location_combo_menu.dine_in_price, combo_menus.dine_in_price) as dine_in_price'),
                    DB::raw('COALESCE(location_combo_menu.take_away_price, combo_menus.take_away_price) as take_away_price'),
                    DB::raw('COALESCE(location_combo_menu.delivery_price, combo_menus.delivery_price) as delivery_price'),
                ]);
            } elseif ($table === 'products') {
                $builder->leftJoin('location_product', function ($join) use ($model, $outletId) {
                    $join->on('location_product.product_id', '=', $model->getTable() . '.id')
                         ->where('location_product.location_id', '=', $outletId);
                })->where(function ($q) {
                    $q->whereNull('location_product.id')
                      ->orWhere('location_product.is_active', '=', true);
                })->select([
                    $model->getTable() . '.*',
                    DB::raw('COALESCE(location_product.sell_price_per_unit, products.sell_price_per_unit) as sell_price_per_unit'),
                ]);
            }
        }
    }
}
