<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateKaraokeShareEmbedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'embedding_confirmation' => ['accepted'],
            'embed_allowed_origins' => ['required', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'embedding_confirmation.accepted' => 'You must confirm the embedding disclosure before enabling embeds.',
        ];
    }

    /**
     * @return list<string>
     */
    public function originInputs(): array
    {
        return [$this->string('embed_allowed_origins')->toString()];
    }

    protected function failedValidation(Validator $validator): void
    {
        $project = $this->route('karaokeProject');

        throw (new ValidationException($validator))
            ->redirectTo(route('karaoke.projects.show', $project));
    }
}
