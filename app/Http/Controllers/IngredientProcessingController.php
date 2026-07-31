<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\IngredientProcessingLog;
use App\Services\IngredientProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class IngredientProcessingController extends Controller
{
    protected $service;

    public function __construct(IngredientProcessingService $service)
    {
        $this->service = $service;
    }

    /**
     * List processing logs (paginated).
     */
    public function index(Request $request)
    {
        $query = IngredientProcessingLog::query();
        if ($request->filled('from')) {
            $query->where('processed_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('processed_at', '<=', $request->query('to'));
        }
        return response()->json($query->orderByDesc('processed_at')->paginate(20));
    }

    /**
     * Preview processing – only checks stock without deduction.
     */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'sometimes|integer|exists:orders,id',
            'items'    => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|integer|exists:ingredients,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $items = $validator->validated()['items'];
        $insufficient = [];
        foreach ($items as $item) {
            $ingredient = Ingredient::find($item['ingredient_id']);
            if ($ingredient->stock < $item['quantity']) {
                $insufficient[] = [
                    'ingredient_id' => $ingredient->id,
                    'available' => $ingredient->stock,
                    'requested' => $item['quantity'],
                ];
            }
        }

        if (!empty($insufficient)) {
            return response()->json([
                'message' => 'Insufficient stock for some ingredients',
                'details' => $insufficient,
            ], 400);
        }

        return response()->json(['message' => 'All ingredients have sufficient stock']);
    }

    /**
     * Process ingredients – deduct stock and create logs.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'sometimes|integer|exists:orders,id',
            'items'    => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|integer|exists:ingredients,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $userId = $request->user()->id ?? null;

        try {
            $logIds = $this->service->processIngredients($userId, $data['order_id'] ?? null, $data['items']);
            $logs = IngredientProcessingLog::whereIn('id', $logIds)->get();
            return response()->json(['message' => 'Ingredients processed', 'logs' => $logs], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Show a single processing log.
     */
    public function show($id)
    {
        $log = IngredientProcessingLog::findOrFail($id);
        return response()->json($log);
    }

    /**
     * Reverse a processing log – add stock back and delete the log.
     */
    public function reverse($id)
    {
        DB::transaction(function () use ($id) {
            $log = IngredientProcessingLog::findOrFail($id);
            $ingredient = $log->ingredient()->lockForUpdate()->first();
            $ingredient->stock += $log->processed_qty;
            $ingredient->save();
            $log->delete();
        });
        return response()->json(['message' => 'Processing reversed']);
    }
}
?>
