<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
                    ? $request->string('sort_col')->toString()
                    : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = GiftCard::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where('card_number', 'like', "%{$search}%");
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'card_number' => ['required', 'string', 'max:60', 'unique:gift_cards,card_number'],
            'description' => ['nullable', 'string', 'max:1000'],
            'initial_value' => ['required', 'numeric', 'min:0'],
            'expire_date' => ['nullable', 'date'],
        ]);
        $payload['number'] = $this->nextNumber();

        return response()->json(GiftCard::create($payload), 201);
    }

    public function show(GiftCard $giftCard): JsonResponse
    {
        return response()->json($giftCard);
    }

    public function update(Request $request, GiftCard $giftCard): JsonResponse
    {
        $payload = $request->validate([
            'card_number' => ['required', 'string', 'max:60', 'unique:gift_cards,card_number,'.$giftCard->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'initial_value' => ['required', 'numeric', 'min:0'],
            'expire_date' => ['nullable', 'date'],
        ]);

        $giftCard->update($payload);

        return response()->json($giftCard->refresh());
    }

    public function destroy(GiftCard $giftCard): JsonResponse
    {
        $giftCard->delete();

        return response()->json(['message' => 'Gift card deleted.']);
    }

    private function nextNumber(): string
    {
        $model = GiftCard::class;
        $next = ($model::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
