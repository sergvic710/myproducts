<?php

namespace App\Http\Requests\Shop;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // Stand in for the Wallet transaction template named after this
            // shop — Wallet exposes no template API.
            'wallet_account' => 'nullable|string|max:255',
            'wallet_category' => 'nullable|string|max:255',
            'wallet_payee' => 'nullable|string|max:255',
        ];
    }
}
