<?php

namespace App\Http\Requests\History;

use Illuminate\Foundation\Http\FormRequest;

class SearchHistoryRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => 'integer',
//            'amount' => 'required|decimal:0,3',
//            'price' => 'required|decimal:0,3',
//            'total' => 'required|decimal:0,3',
            'date' => 'date|nullable',
//            'shop_id' => 'required|exists:shops,id',
        ];
    }
}
