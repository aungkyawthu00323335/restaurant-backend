<?php

namespace App\Http\Requests;

use App\Models\Modifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ModifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $modifier = $this->route('modifier');
        $modifierId = $modifier instanceof Modifier ? $modifier->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('modifiers', 'name')->ignore($modifierId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'selection_type' => ['required', 'string', Rule::in(['single', 'multiple'])],
            'min_selection' => ['required', 'integer', 'min:0', 'max:100'],
            'max_selection' => ['required', 'integer', 'min:1', 'max:100'],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['required', 'array', 'min:1', 'max:100'],
            'options.*.name' => ['required', 'string', 'max:160'],
            'options.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'options.*.is_default' => ['sometimes', 'boolean'],
            'options.*.cost_adjustment' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'options.*.is_active' => ['sometimes', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'options.*.note' => ['nullable', 'string', 'max:500'],
            'options.*.ingredients' => ['nullable', 'array', 'max:100'],
            'options.*.ingredients.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'options.*.ingredients.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $min = (int) $this->input('min_selection', 0);
            $max = (int) $this->input('max_selection', 1);
            $options = $this->input('options', []);
            $selectionType = (string) $this->input('selection_type');

            if ($max < $min) {
                $validator->errors()->add('max_selection', 'Maximum selection must be at least the minimum selection.');
            }

            if ($this->boolean('is_required') && $min < 1) {
                $validator->errors()->add('min_selection', 'A required modifier must have a minimum selection of at least one.');
            }

            if ($selectionType === 'single' && $max !== 1) {
                $validator->errors()->add('max_selection', 'Single selection modifier groups must have a maximum selection of one.');
            }

            if (is_array($options) && $max > count($options)) {
                $validator->errors()->add('max_selection', 'Maximum selection cannot exceed the number of options.');
            }

            $names = [];
            $defaultCount = 0;
            foreach (is_array($options) ? $options : [] as $index => $option) {
                $name = mb_strtolower(trim((string) ($option['name'] ?? '')));
                if ($name !== '' && in_array($name, $names, true)) {
                    $validator->errors()->add("options.$index.name", 'Option names must be unique.');
                }
                $names[] = $name;
                $defaultCount += ($option['is_default'] ?? false) === true ? 1 : 0;
            }

            if ($defaultCount > 1) {
                $validator->errors()->add('options', 'Only one option can be the default.');
            }
        }];
    }
}
