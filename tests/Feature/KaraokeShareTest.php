<?php

use App\Enums\KaraokeProjectStatus;
use App\Enums\KaraokeShareExpirationOption;
use App\Models\KaraokeProjectShare;
use App\Support\Karaoke\KaraokeProjectShareService;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
    $response->assertSessionMissing('share_url');

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

    $response->assertSessionMissing('share_url');
    expect(collect($response->getSession()->all())->flatten()->join(' '))->not->toMatch('/\/karaoke\/shared\//');

    $share = KaraokeProjectShare::query()->firstOrFail();
    $shareUrl = app(KaraokeProjectShareService::class)->ownerShareUrl($share);
    expect($shareUrl)->not->toBeNull();
    $token = $this->extractTokenFromShareUrl((string) $shareUrl);

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

    $response->assertSessionMissing('share_url');

    $share = KaraokeProjectShare::query()->whereKey($initial['share']->id)->firstOrFail();
    $newUrl = (string) app(KaraokeProjectShareService::class)->ownerShareUrl($share);
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

it('does not place share urls or tokens in session when creating a link', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $response = $this->actingAs($user)
        ->post(route('karaoke.projects.share.store', $project), $this->shareFormPayload());

    $sessionPayload = json_encode($response->getSession()->all());

    $response->assertSessionMissing('share_url');
    expect($sessionPayload)->not->toContain('/karaoke/shared/');
    expect($sessionPayload)->not->toMatch('/"token"/');
});

it('does not place share urls or tokens in session when rotating a link', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    $response = $this->actingAs($user)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1']);

    $sessionPayload = json_encode($response->getSession()->all());

    $response->assertSessionMissing('share_url');
    expect($sessionPayload)->not->toContain('/karaoke/shared/');
    expect($sessionPayload)->not->toMatch('/"token"/');
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
        ->assertSessionMissing('share_url');
});

it('stores expiration according to the selected option', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)->post(route('karaoke.projects.share.store', $project), [
        'sharing_confirmation' => '1',
        'expires_in' => 'never',
    ]);

    $share = KaraokeProjectShare::query()
        ->where('karaoke_project_id', $project->id)
        ->firstOrFail();
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
