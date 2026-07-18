<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportKaraokeProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'import' => ['required', 'file', 'mimes:json,txt', 'max:1024'],
        ];
    }
}
