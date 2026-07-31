<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientProcessingLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class IngredientProcessingService
{
    /**
     * Process a list of ingredients, deduct stock and create logs.
     *
     * @param int|null $userId   ID of the staff performing the processing
     * @param int|null $orderId  Optional order reference
     * @param array $items       Array of ['ingredient_id' => int, 'quantity' => int]
     * @return array             Array of created log IDs
     * @throws \Exception       When stock is insufficient or DB error occurs
     */
    public function processIngredients(?int $userId, ?int $orderId, array $items): array
    {
        return DB::transaction(function () use ($userId, $orderId, $items) {
            $logIds = [];
            foreach ($items as $item) {
                $ingredient = Ingredient::where('id', $item['ingredient_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $qty = $item['quantity'];
                if ($ingredient->stock < $qty) {
                    throw new \Exception('Insufficient stock for ingredient ID ' . $ingredient->id);
                }

                $stockBefore = $ingredient->stock;
                $ingredient->stock = $stockBefore - $qty;
                $ingredient->save();

                $log = IngredientProcessingLog::create([
                    'ingredient_id' => $ingredient->id,
                    'order_id'      => $orderId,
                    'processed_qty' => $qty,
                    'stock_before'  => $stockBefore,
                    'stock_after'   => $ingredient->stock,
                    'user_id'       => $userId,
                ]);
                $logIds[] = $log->id;
            }
            return $logIds;
        }, 5); // retry up to 5 times on deadlock
    }
}
?>
