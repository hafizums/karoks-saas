<?php

use App\Enums\KaraokeProjectStatus;
use App\Enums\KaraokeShareExpirationOption;
use App\Models\KaraokeProjectShare;
use App\Support\Karaoke\KaraokeProjectShareService;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\KaraokeShareTestHelpers;
use Tests\Support\KaraokeTestTheme;

uses(DatabaseTransactions::class);
uses(KaraokeShareTestHelpers::class);

beforeEach(function () {
    if (! Theme::query()->where('folder', 'anchor')->exists()) {
        Theme::query()->create([
            'name' => 'Anchor Theme',
            'folder' => 'anchor',
            'active' => true,
            'version' => 1.0,
        ]);
    }

    KaraokeTestTheme::register();
    Storage::fake('local');
    Http::fake();
});

it('redirects guests from owner share-management routes', function () {
    $project = $this->createShareReadyProject($this->createShareUser());

    $this->post(route('karaoke.projects.share.store', $project))->assertRedirect(route('login'));
    $this->post(route('karaoke.projects.share.rotate', $project))->assertRedirect(route('login'));
    $this->delete(route('karaoke.projects.share.destroy', $project))->assertRedirect(route('login'));
});

it('forbids another user from creating a share', function () {
    $owner = $this->createShareUser();
    $other = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);

    $this->actingAs($other)
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload())
        ->assertForbidden();
});

it('forbids another user from rotating a share', function () {
    $owner = $this->createShareUser();
    $other = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $this->createShareForProject($project, $owner);

    $this->actingAs($other)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1'])
        ->assertForbidden();
});

it('forbids another user from revoking a share', function () {
    $owner = $this->createShareUser();
    $other = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $this->createShareForProject($project, $owner);

    $this->actingAs($other)
        ->delete(route('karaoke.projects.share.destroy', $project))
        ->assertForbidden();
});

it('cannot share an unready project', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user, [
        'transcript' => null,
        'status' => KaraokeProjectStatus::Uploaded,
    ]);

    $this->actingAs($user)
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload())
        ->assertForbidden();
});

it('allows a ready owner to create a share', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $response = $this->actingAs($user)
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload());

    $response->assertRedirect(route('karaoke.projects.show', $project));
    $response->assertSessionHas('share_url');

    $share = KaraokeProjectShare::query()->where('karaoke_project_id', $project->id)->first();
    expect($share)->not->toBeNull();
    expect($share->isActive())->toBeTrue();
});

it('requires explicit sharing confirmation to create a share', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->post(route('karaoke.projects.share.store', $project), [
            'expires_in' => '7d',
        ])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('sharing_confirmation');
});

it('validates expiration options strictly', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->post(route('karaoke.projects.share.store', $project), [
            'sharing_confirmation' => '1',
            'expires_in' => '365d',
        ])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('expires_in');
});

it('does not store the raw token in the database', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $response = $this->actingAs($user)
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload());

    $url = (string) $response->getSession()->get('share_url');
    $token = $this->extractTokenFromShareUrl($url);
    $share = KaraokeProjectShare::query()->firstOrFail();

    expect($token)->not->toBeEmpty();
    expect($share->token_hash)->not->toBe($token);
    expect($share->token_ciphertext)->not->toContain($token);
    expect($share->toArray())->not->toHaveKey('token_hash');
    expect($share->toArray())->not->toHaveKey('token_ciphertext');
});

it('allows only one active share per project', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload())
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('share');

    $activeCount = KaraokeProjectShare::query()
        ->where('karaoke_project_id', $project->id)
        ->get()
        ->filter(fn (KaraokeProjectShare $share): bool => $share->isActive())
        ->count();

    expect($activeCount)->toBe(1);
});

it('does not create multiple active links from duplicate submissions', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload());
    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload());

    $activeCount = KaraokeProjectShare::query()
        ->where('karaoke_project_id', $project->id)
        ->get()
        ->filter(fn (KaraokeProjectShare $share): bool => $share->isActive())
        ->count();

    expect($activeCount)->toBe(1);
});

it('rotates a share and invalidates the previous token', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $initial = $this->createShareForProject($project, $user);

    $this->get(route('karaoke.shared.show', [
        'share' => $initial['share']->public_id,
        'token' => $initial['token'],
    ]))->assertOk();

    $response = $this->actingAs($user)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1']);

    $newUrl = (string) $response->getSession()->get('share_url');
    $newToken = $this->extractTokenFromShareUrl($newUrl);

    $this->get(route('karaoke.shared.show', [
        'share' => $initial['share']->public_id,
        'token' => $initial['token'],
    ]))->assertNotFound();

    $this->get(route('karaoke.shared.show', [
        'share' => $initial['share']->public_id,
        'token' => $newToken,
    ]))->assertOk();
});

it('revokes a share immediately', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    $this->actingAs($user)
        ->delete(route('karaoke.projects.share.destroy', $project))
        ->assertRedirect(route('karaoke.projects.show', $project));

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('treats expired shares as invalid', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user, KaraokeShareExpirationOption::Hours24);

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('removes shares when a project is deleted', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    $shareId = $shareData['share']->id;

    $this->actingAs($user)->delete(route('karaoke.projects.destroy', $project));

    expect(KaraokeProjectShare::query()->whereKey($shareId)->exists())->toBeFalse();
});

it('removes shares when a user is force deleted', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    $shareId = $shareData['share']->id;

    $user->forceDelete();

    expect(KaraokeProjectShare::query()->whereKey($shareId)->exists())->toBeFalse();
});

it('locks concurrent share creation to a single active link', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $service = app(KaraokeProjectShareService::class);

    DB::transaction(function () use ($service, $project, $user): void {
        $service->createShare($project, $user, KaraokeShareExpirationOption::Days7);

        expect(fn () => $service->createShare($project, $user, KaraokeShareExpirationOption::Days7))
            ->toThrow(ValidationException::class);
    });
});

it('allows owner to inspect share status on the project page', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    $this->actingAs($user)
        ->get(route('karaoke.projects.show', $project))
        ->assertOk()
        ->assertSee('Public sharing')
        ->assertSee('Active');
});

it('fails safely when token decryption fails during owner url generation', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'token_ciphertext' => 'invalid-ciphertext-payload',
    ]);

    expect(app(KaraokeProjectShareService::class)->ownerShareUrl($shareData['share']->fresh()))->toBeNull();

    $this->actingAs($user)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1'])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHas('share_url');
});

it('stores expiration according to the selected option', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)->post(route('karaoke.projects.share.store', $project), [
        'sharing_confirmation' => '1',
        'expires_in' => 'never',
    ]);

    $share = KaraokeProjectShare::query()->firstOrFail();
    expect($share->expires_at)->toBeNull();
});

it('does not expose hidden token fields in share json payloads', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    $payload = $shareData['share']->toArray();

    expect($payload)->not->toHaveKey('token_hash');
    expect($payload)->not->toHaveKey('token_ciphertext');
    expect($payload)->not->toHaveKey('id');
});

it('uses hash_equals semantics for token verification', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    $service = app(KaraokeProjectShareService::class);

    expect($service->resolvePublicShare($shareData['share']->public_id, $shareData['token']))->not->toBeNull();
    expect($service->resolvePublicShare($shareData['share']->public_id, $shareData['token'].'x'))->toBeNull();
});

it('does not make live http requests during share management', function () {
    Http::fake();

    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload());

    Http::assertNothingSent();
});
