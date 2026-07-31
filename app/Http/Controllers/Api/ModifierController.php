<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModifierRequest;
use App\Models\Modifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ModifierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);
        $query = Modifier::query()
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->latest('id');

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(ModifierRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['slug'] = $this->uniqueSlug($payload['name']);

        return response()->json(Modifier::create($payload), Response::HTTP_CREATED);
    }

    public function show(Modifier $modifier): JsonResponse
    {
        return response()->json($modifier);
    }

    public function update(ModifierRequest $request, Modifier $modifier): JsonResponse
    {
        $payload = $request->validated();
        $payload['slug'] = $this->uniqueSlug($payload['name'], $modifier->id);
        $modifier->update($payload);

        return response()->json($modifier->refresh());
    }

    public function destroy(Modifier $modifier): JsonResponse
    {
        if ($modifier->foodMenus()->exists()) {
            return response()->json([
                'message' => 'This modifier group is assigned to one or more food menus. Remove those assignments before deleting it.',
            ], Response::HTTP_CONFLICT);
        }

        $modifier->delete();

        return response()->json(['message' => 'Modifier deleted.']);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'modifier';
        $slug = $base;
        $index = 2;

        while (Modifier::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$index}";
            $index++;
        }

        return $slug;
    }
}
