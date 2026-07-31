<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use Illuminate\Support\Facades\DB;

class MigrateLegacyStock extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'inventory:migrate-legacy
                            {--dry-run : Show what would be created without actually inserting}';

    /**
     * The console command description.
     */
    protected $description = 'Migrate existing stock data (initial_stock + movements) into ingredient_batches table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY-RUN mode — no records will be created.');
        }

        $this->info('Loading active ingredients…');

        // ── 1. Collect ingredients, skip those that already have batches ──
        $alreadyMigrated = IngredientBatch::distinct()->pluck('ingredient_id')->toArray();

        $ingredients = Ingredient::where('is_active', true)
            ->whereNotIn('id', $alreadyMigrated)
            ->get();

        if ($ingredients->isEmpty()) {
            $this->info('Nothing to migrate — all active ingredients already have batches (or none exist).');
            return self::SUCCESS;
        }

        $skippedCount = count($alreadyMigrated);
        if ($skippedCount > 0) {
            $this->warn("Skipping {$skippedCount} ingredient(s) that already have batches.");
        }

        // ── 2. Fetch net movements grouped by ingredient + location ──
        //    Same formula as StockReportController:
        //    SUM(CASE WHEN direction='IN' THEN qty ELSE -qty END)
        $movements = IngredientStockMovement::select(
                'ingredient_id',
                'location_id',
                DB::raw("SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END) as net_movement")
            )
            ->groupBy('ingredient_id', 'location_id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn ($rows) => $rows->keyBy('location_id'));

        // ── 3. Fetch earliest movement date per ingredient + location (for received_at) ──
        $earliestDates = IngredientStockMovement::select(
                'ingredient_id',
                'location_id',
                DB::raw('MIN(COALESCE(occurred_at, created_at)) as earliest_date')
            )
            ->groupBy('ingredient_id', 'location_id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn ($rows) => $rows->pluck('earliest_date', 'location_id'));

        // ── 4. Process each ingredient ──
        $bar = $this->output->createProgressBar($ingredients->count());
        $bar->start();

        $created  = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($ingredients as $ingredient) {
            try {
                $batches = $this->buildBatchesForIngredient(
                    $ingredient,
                    $movements,
                    $earliestDates
                );

                foreach ($batches as $batch) {
                    if ($dryRun) {
                        $this->line('');
                        $this->line("  [DRY-RUN] Would create batch: ingredient #{$ingredient->id} ({$ingredient->name}), "
                            . "location #{$batch['location_id']}, qty={$batch['original_qty']}, cost={$batch['unit_cost']}");
                    } else {
                        IngredientBatch::create($batch);
                    }
                    $created++;
                }

                if (empty($batches)) {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->error("  Error processing ingredient #{$ingredient->id} ({$ingredient->name}): {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ── 5. Summary ──
        $this->info('┌─────────────────────────────────┐');
        $this->info('│  Migration Summary              │');
        $this->info('├─────────────────────────────────┤');
        $this->info("│  Batches created : {$created}");
        $this->info("│  Skipped (zero)  : {$skipped}");
        $this->info("│  Already migrated: {$skippedCount}");
        $this->info("│  Errors          : {$errors}");
        $this->info('└─────────────────────────────────┘');

        if ($dryRun && $created > 0) {
            $this->warn('Re-run without --dry-run to actually create the batches.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build an array of batch payloads for a single ingredient.
     * Mirrors the stock-calculation logic from StockReportController.
     */
    private function buildBatchesForIngredient(
        Ingredient $ingredient,
        $movements,
        $earliestDates
    ): array {
        // ── Gather every location_id this ingredient references ──
        $locationStocks = []; // location_id => stock qty

        // A) initial_stock_data → [{location_id, quantity}, …]
        $initialData = $ingredient->initial_stock_data;
        if ($initialData) {
            $initialData = is_array($initialData) ? $initialData : json_decode($initialData, true);
            if (is_array($initialData)) {
                foreach ($initialData as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $locId = isset($entry['location_id']) ? (int) $entry['location_id'] : null;
                    $qty   = (float) ($entry['quantity'] ?? 0);
                    if ($locId === null) {
                        continue;
                    }
                    $locationStocks[$locId] = ($locationStocks[$locId] ?? 0) + $qty;
                }
            }
        }

        // B) Add net movements per location
        $ingredientMovements = $movements[$ingredient->id] ?? collect();
        foreach ($ingredientMovements as $locId => $row) {
            $locationStocks[(int) $locId] = ($locationStocks[(int) $locId] ?? 0) + (float) $row->net_movement;
        }

        // ── Build batch records for positive-stock locations ──
        $unitCost = $ingredient->conversion_rate > 0
            ? round($ingredient->purchase_price / $ingredient->conversion_rate, 4)
            : 0;

        $batches = [];

        foreach ($locationStocks as $locId => $stock) {
            $stock = round($stock, 4);
            if ($stock <= 0) {
                continue;
            }

            // Use earliest movement date as received_at, fallback to now()
            $receivedAt = data_get($earliestDates, "{$ingredient->id}.{$locId}");
            $receivedAt = $receivedAt ? \Carbon\Carbon::parse($receivedAt) : now();

            $batches[] = [
                'ingredient_id'    => $ingredient->id,
                'location_id'      => $locId,
                'purchase_item_id' => null,
                'original_qty'     => $stock,
                'usable_qty'       => $stock,
                'unit_cost'        => $unitCost,
                'received_at'      => $receivedAt,
                'expiry_date'      => null,
            ];
        }

        return $batches;
    }
}
