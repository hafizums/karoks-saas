<?php

namespace App\Http\Requests;

use App\Rules\ValidKaraokeAudio;
use Illuminate\Foundation\Http\FormRequest;

class StoreKaraokeProjectRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:191'],
            'artist' => ['nullable', 'string', 'max:191'],
            'audio' => ['required', 'file', 'max:51200', new ValidKaraokeAudio()],
            'rights_confirmed' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audio.required' => 'Please choose an audio file.',
            'audio.max' => 'This file is larger than 50 MB. Choose a smaller track.',
            'rights_confirmed.accepted' => 'You must confirm that you own this audio or have permission to process it.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'artist' => is_string($this->artist) ? trim($this->artist) : $this->artist,
        ]);
    }
}
