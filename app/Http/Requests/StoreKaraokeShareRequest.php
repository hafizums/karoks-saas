<?php

namespace App\Http\Requests;

use App\Enums\KaraokeShareExpirationOption;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreKaraokeShareRequest extends FormRequest
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
            'expires_in' => ['required', Rule::in(KaraokeShareExpirationOption::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sharing_confirmation.accepted' => 'You must confirm the public sharing disclosure before creating a link.',
        ];
    }

    public function expirationOption(): KaraokeShareExpirationOption
    {
        return KaraokeShareExpirationOption::from($this->string('expires_in')->toString());
    }

    protected function failedValidation(Validator $validator): void
    {
        $project = $this->route('karaokeProject');

        throw (new ValidationException($validator))
            ->redirectTo(route('karaoke.projects.show', $project));
    }
}
