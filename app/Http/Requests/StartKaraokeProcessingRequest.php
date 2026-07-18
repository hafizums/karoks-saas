<?php

namespace App\Http\Requests;

use App\Support\Karaoke\Processing\KaraokeProcessingDriverResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StartKaraokeProcessingRequest extends FormRequest
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
            'provider_consent' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $resolver = app(KaraokeProcessingDriverResolver::class);

            if (! $resolver->requiresProviderConsent()) {
                return;
            }

            $project = $this->route('karaokeProject');

            if ($project?->provider_consent_confirmed_at !== null) {
                return;
            }

            if (! $this->boolean('provider_consent')) {
                $validator->errors()->add(
                    'provider_consent',
                    'You must consent to third-party processing before starting.',
                );
            }
        });
    }

    public function providerConsentAccepted(): bool
    {
        return $this->boolean('provider_consent');
    }

    protected function failedValidation(Validator $validator): void
    {
        $project = $this->route('karaokeProject');

        throw (new ValidationException($validator))
            ->redirectTo(route('karaoke.projects.show', $project));
    }
}
