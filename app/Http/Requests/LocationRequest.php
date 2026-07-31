<?php

namespace App\Http\Requests;

use App\Models\Location;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'opening_time' => $this->normalizeTime($this->input('opening_time')),
            'closing_time' => $this->normalizeTime($this->input('closing_time')),
        ]);
    }

    public function rules(): array
    {
        $location = $this->route('location');
        $locationId = $location instanceof Location ? $location->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('locations', 'name')->ignore($locationId),
            ],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60', 'regex:/^[0-9+()\-\s.]*$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'tax_identification_number' => ['nullable', 'string', 'max:120'],
            'image_base64' => ['nullable', 'string'],
            'image_name' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'is_head_office' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            
            'food_menus' => ['nullable', 'array'],
            'food_menus.*.id' => ['required_with:food_menus', 'integer', 'exists:food_menus,id'],
            'food_menus.*.dine_in_price' => ['nullable', 'numeric', 'min:0'],
            'food_menus.*.take_away_price' => ['nullable', 'numeric', 'min:0'],
            'food_menus.*.delivery_price' => ['nullable', 'numeric', 'min:0'],
            'food_menus.*.is_active' => ['nullable', 'boolean'],

            'combo_menus' => ['nullable', 'array'],
            'combo_menus.*.id' => ['required_with:combo_menus', 'integer', 'exists:combo_menus,id'],
            'combo_menus.*.dine_in_price' => ['nullable', 'numeric', 'min:0'],
            'combo_menus.*.take_away_price' => ['nullable', 'numeric', 'min:0'],
            'combo_menus.*.delivery_price' => ['nullable', 'numeric', 'min:0'],
            'combo_menus.*.is_active' => ['nullable', 'boolean'],

            'products' => ['nullable', 'array'],
            'products.*.id' => ['required_with:products', 'integer', 'exists:products,id'],
            'products.*.sell_price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'products.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    private function normalizeTime(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $value = trim($value);
        foreach (['H:i', 'H:i:s', 'h:i A', 'g:i A'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($time !== false) {
                return $time->format('H:i');
            }
        }

        return $value;
    }
}
