<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKaraokeProjectEditorRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'min:1', 'max:191'],
            'artist' => ['sometimes', 'nullable', 'string', 'max:191'],
            'theme' => ['sometimes', 'array'],
            'theme.backgroundPreset' => ['sometimes', 'string'],
            'theme.lyricSize' => ['sometimes', 'string'],
            'theme.baseColor' => ['sometimes', 'string'],
            'theme.highlightColor' => ['sometimes', 'string'],
            'words' => ['sometimes', 'array'],
            'words.*' => ['string', 'min:1', 'max:200'],
        ];
    }
}
