<?php

namespace App\Http\Requests;

use App\Models\FoodMenu;
use App\Models\Ingredient;
use App\Models\Modifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FoodMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $foodMenu = $this->route('food_menu');
        $foodMenuId = $foodMenu instanceof FoodMenu ? $foodMenu->id : null;
        $method = (string) $this->input('stock_deduction_method');
        $requiresIngredients = in_array($method, ['deduct_ingredient_on_sale', 'production_stock'], true);
        $maxEncodedLength = (int) ceil(max(1, (int) config('pos.max_image_bytes', 5 * 1024 * 1024)) * 4 / 3) + 128;

        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:80', Rule::unique('food_menus', 'code')->ignore($foodMenuId)],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'printer_id' => [
                'required',
                'integer',
                Rule::exists('printers', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'unit_id' => [
                'required',
                'integer',
                Rule::exists('food_menu_units', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'stock_deduction_method' => ['required', 'string', Rule::in(FoodMenu::STOCK_DEDUCTION_METHODS)],
            'dine_in_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'take_away_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'delivery_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'low_stock_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'],
            'description' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],

            'ingredients' => [
                Rule::requiredIf($requiresIngredients),
                'nullable',
                'array',
                'max:200',
                Rule::when($requiresIngredients, ['min:1']),
            ],
            'ingredients.*.ingredient_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('ingredients', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'ingredients.*.required_qty' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],

            'modifier_groups' => ['nullable', 'array', 'max:50'],
            'modifier_groups.*.modifier_group_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('modifiers', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'modifier_groups.*.is_required' => ['required', 'boolean'],
            'modifier_groups.*.min_selection' => ['required', 'integer', 'min:0', 'max:100'],
            'modifier_groups.*.max_selection' => ['required', 'integer', 'min:1', 'max:100'],
            'modifier_groups.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $method = (string) $this->input('stock_deduction_method');
            $ingredients = $this->input('ingredients', []);
            $modifierGroups = $this->input('modifier_groups', []);

            if ($method === 'no_stock' && is_array($ingredients) && $ingredients !== []) {
                $validator->errors()->add('ingredients', 'No Stock food menus cannot have ingredient stock mapping.');
            }

            if ($method !== 'production_stock' && $this->filled('low_stock_qty')) {
                $validator->errors()->add('low_stock_qty', 'Low stock quantity is only available for Production Stock food menus.');
            }

            $ingredientIds = collect(is_array($ingredients) ? $ingredients : [])
                ->pluck('ingredient_id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->all();
            $ingredientModels = Ingredient::query()
                ->whereIn('id', $ingredientIds)
                ->with('consumptionUnit:id,name')
                ->get()
                ->keyBy('id');

            foreach (is_array($ingredients) ? $ingredients : [] as $index => $row) {
                $ingredient = $ingredientModels->get((int) ($row['ingredient_id'] ?? 0));
                if ($ingredient !== null && $ingredient->consumption_unit_id === null) {
                    $validator->errors()->add("ingredients.$index.ingredient_id", 'The ingredient must have a consumption unit before it can be mapped.');
                }
            }

            $modifierIds = collect(is_array($modifierGroups) ? $modifierGroups : [])
                ->pluck('modifier_group_id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->all();
            $modifiers = Modifier::query()->whereIn('id', $modifierIds)->get()->keyBy('id');

            foreach (is_array($modifierGroups) ? $modifierGroups : [] as $index => $row) {
                $min = (int) ($row['min_selection'] ?? 0);
                $max = (int) ($row['max_selection'] ?? 0);
                $required = filter_var($row['is_required'] ?? false, FILTER_VALIDATE_BOOL);
                $modifier = $modifiers->get((int) ($row['modifier_group_id'] ?? 0));

                if ($max < $min) {
                    $validator->errors()->add("modifier_groups.$index.max_selection", 'Maximum selection must be at least the minimum selection.');
                }
                if ($required && $min < 1) {
                    $validator->errors()->add("modifier_groups.$index.min_selection", 'A required modifier must have at least one selection.');
                }
                if ($modifier?->selection_type === 'single' && $max !== 1) {
                    $validator->errors()->add("modifier_groups.$index.max_selection", 'A single-selection modifier must have a maximum of one.');
                }
                $optionCount = is_array($modifier?->options) ? count($modifier->options) : 0;
                if ($optionCount > 0 && $max > $optionCount) {
                    $validator->errors()->add("modifier_groups.$index.max_selection", 'Maximum selection cannot exceed the modifier item count.');
                }
            }
        }];
    }
}
