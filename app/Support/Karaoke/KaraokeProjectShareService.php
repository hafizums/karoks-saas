<?php

namespace App\Support\Karaoke;

use App\Enums\KaraokeShareExpirationOption;
use App\Models\KaraokeProject;
use App\Models\KaraokeProjectShare;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KaraokeProjectShareService
{
    /**
     * @return array{share: KaraokeProjectShare, url: string}
     */
    public function createShare(
        KaraokeProject $project,
        User $user,
        KaraokeShareExpirationOption $expiration,
    ): array {
        $this->assertOwnerCanShare($project, $user);

        return $this->runShareTransaction(function () use ($project, $user, $expiration): array {
            KaraokeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->first();

            $existingActive = $this->lockActiveShareForProject($project);

            if ($existingActive !== null) {
                throw ValidationException::withMessages([
                    'share' => 'An active public link already exists for this project.',
                ]);
            }

            [$token, $tokenHash, $tokenCiphertext] = $this->generateTokenMaterial();

            $share = KaraokeProjectShare::query()->create([
                'karaoke_project_id' => $project->getKey(),
                'created_by_user_id' => $user->getKey(),
                'token_hash' => $tokenHash,
                'token_ciphertext' => $tokenCiphertext,
                'expires_at' => $expiration->expiresAt(),
            ]);

            return [
                'share' => $share,
                'url' => $this->buildShareUrl($share, $token),
            ];
        });
    }

    /**
     * @return array{share: KaraokeProjectShare, url: string}
     */
    public function rotateShare(KaraokeProject $project, User $user): array
    {
        $this->assertOwnerCanShare($project, $user);

        return $this->runShareTransaction(function () use ($project, $user): array {
            KaraokeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->first();

            $share = $this->lockActiveShareForProject($project);

            if ($share === null) {
                throw ValidationException::withMessages([
                    'share' => 'No active public link exists for this project.',
                ]);
            }

            if ((int) $share->created_by_user_id !== (int) $user->getKey()) {
                abort(403);
            }

            [$token, $tokenHash, $tokenCiphertext] = $this->generateTokenMaterial();

            $share->forceFill([
                'token_hash' => $tokenHash,
                'token_ciphertext' => $tokenCiphertext,
            ])->save();

            return [
                'share' => $share->fresh(),
                'url' => $this->buildShareUrl($share, $token),
            ];
        });
    }

    public function revokeShare(KaraokeProject $project, User $user): void
    {
        $this->assertOwnerCanShare($project, $user);

        $this->runShareTransaction(function () use ($project, $user): void {
            KaraokeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->first();

            $share = $this->lockActiveShareForProject($project);

            if ($share === null) {
                throw ValidationException::withMessages([
                    'share' => 'No active public link exists for this project.',
                ]);
            }

            if ((int) $share->created_by_user_id !== (int) $user->getKey()) {
                abort(403);
            }

            $share->forceFill([
                'revoked_at' => now(),
            ])->save();
        });
    }

    public function activeShareForProject(KaraokeProject $project): ?KaraokeProjectShare
    {
        return KaraokeProjectShare::query()
            ->where('karaoke_project_id', $project->getKey())
            ->orderByDesc('id')
            ->get()
            ->first(fn (KaraokeProjectShare $share): bool => $share->isActive());
    }

    public function ownerShareUrl(KaraokeProjectShare $share): ?string
    {
        try {
            $token = $this->decryptStoredToken($share);
        } catch (DecryptException) {
            return null;
        }

        return $this->buildShareUrl($share, $token);
    }

    public function resolvePublicShare(string $sharePublicId, string $token): ?KaraokeProjectShare
    {
        if ($sharePublicId === '' || $token === '') {
            return null;
        }

        $share = KaraokeProjectShare::query()
            ->where('public_id', $sharePublicId)
            ->first();

        if ($share === null || ! $this->tokenMatches($share, $token) || ! $share->isActive()) {
            return null;
        }

        $project = $share->karaokeProject;

        if ($project === null || ! $project->isReadyForPlayback()) {
            return null;
        }

        if ($project->playbackAudioPath() === null || $project->parsedTranscript() === null) {
            return null;
        }

        return $share;
    }

    public function buildShareUrl(KaraokeProjectShare $share, string $token): string
    {
        return route('karaoke.shared.show', [
            'share' => $share->public_id,
            'token' => $token,
        ]);
    }

    public function buildPublicAudioUrl(KaraokeProjectShare $share, string $token): string
    {
        return route('karaoke.shared.audio', [
            'share' => $share->public_id,
            'token' => $token,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function generateTokenMaterial(): array
    {
        $token = $this->generateToken();

        return [
            $token,
            $this->hashToken($token),
            Crypt::encryptString($token),
        ];
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function tokenMatches(KaraokeProjectShare $share, string $token): bool
    {
        return hash_equals($share->token_hash, $this->hashToken($token));
    }

    private function decryptStoredToken(KaraokeProjectShare $share): string
    {
        return Crypt::decryptString($share->token_ciphertext);
    }

    private function lockActiveShareForProject(KaraokeProject $project): ?KaraokeProjectShare
    {
        $shares = KaraokeProjectShare::query()
            ->where('karaoke_project_id', $project->getKey())
            ->lockForUpdate()
            ->orderByDesc('id')
            ->get();

        return $shares->first(fn (KaraokeProjectShare $share): bool => $share->isActive());
    }

    private function assertOwnerCanShare(KaraokeProject $project, User $user): void
    {
        if ((int) $project->user_id !== (int) $user->getKey()) {
            abort(403);
        }

        if (! $project->isReadyForPlayback()) {
            throw ValidationException::withMessages([
                'share' => 'Processing must complete before this project can be shared.',
            ]);
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runShareTransaction(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 5;

        while (true) {
            try {
                return DB::transaction($callback);
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (QueryException $exception) {
                if (! $this->isSqliteBusyException($exception) || ++$attempts >= $maxAttempts) {
                    throw $exception;
                }

                usleep(10_000 * $attempts);
            }
        }
    }

    private function isSqliteBusyException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'sqlite_busy');
    }
}
