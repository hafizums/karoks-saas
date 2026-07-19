<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RotateKaraokeShareRequest extends FormRequest
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
            'sharing_confirmation' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sharing_confirmation.accepted' => 'You must confirm the public sharing disclosure before rotating the link.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $project = $this->route('karaokeProject');

        throw (new ValidationException($validator))
            ->redirectTo(route('karaoke.projects.show', $project));
    }
}
