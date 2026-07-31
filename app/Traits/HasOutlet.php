<?php

namespace App\Traits;

use App\Models\Scopes\OutletScope;

trait HasOutlet
{
    /**
     * Boot the trait to apply the global scope.
     */
    protected static function bootHasOutlet(): void
    {
        static::addGlobalScope(new OutletScope);
    }

    /**
     * Get the name of the column used for outlet filtering.
     */
    public function getOutletColumnName(): string
    {
        // Allow models to override this property, e.g. protected $outletColumn = 'location_id';
        return $this->outletColumn ?? 'outlet_id';
    }
}
