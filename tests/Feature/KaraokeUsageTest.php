<?php

use App\Contracts\KaraokeProcessor;
use App\Enums\KaraokeProjectStatus;
use App\Exceptions\KaraokeProcessingDisabledException;
use App\Exceptions\KaraokeProcessingException;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Models\KaraokeUsageRecord;
use App\Models\User;
use App\Support\KaraokeProcessingEntitlementResolver;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeUsageService;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\FlakyKaraokeProcessor;
use Tests\Support\KaraokeTestTheme;
use Tests\Support\NeverCalledKaraokeProcessor;
use Wave\Plan;
use Wave\Subscription;

uses(DatabaseTransactions::class);

function usageRecordsFor(User $user)
{
    return KaraokeUsageRecord::query()->where('user_id', $user->id);
}

function usageUser(array $attributes = []): User
{
    return User::factory()->create(array_merge(['verified' => 1], $attributes));
}

function createUsageProject(User $user, array $attributes = []): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
    $audioBytes = file_get_contents(base_path('tests/fixtures/sample.wav'));

    Storage::disk('local')->put($path, $audioBytes);

    return KaraokeProject::factory()->create(array_merge([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => strlen($audioBytes),
        'status' => KaraokeProjectStatus::Uploaded,
    ], $attributes));
}

function subscribeUserToPlan(User $user, Plan $plan): Subscription
{
    return Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $plan->id,
        'vendor_slug' => 'stripe',
        'vendor_customer_id' => 'cus_'.uniqid(),
        'vendor_subscription_id' => 'sub_'.uniqid(),
        'cycle' => 'month',
        'status' => 'active',
        'seats' => 1,
    ]);
}

function createPlanWithProcessingLimit(string $name, int $limit): Plan
{
    $role = Role::firstOrCreate(['name' => strtolower($name).'_role', 'guard_name' => 'web']);

    return Plan::create([
        'name' => $name,
        'description' => $name.' plan',
        'features' => ['karaoke'],
        'monthly_price' => '10.00',
        'active' => true,
        'role_id' => $role->id,
        'limits' => [
            'karoks_processing_jobs_monthly' => $limit,
            'api_keys' => 3,
        ],
    ]);
}

function runUsageProcessingJob(KaraokeProject $project): void
{
    $project->refresh();

    (new ProcessKaraokeProject($project->id, (string) $project->processing_run_id))->handle(
        app(KaraokeProcessor::class),
        app(KaraokeProcessingStateService::class),
    );
}

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
    Queue::fake();
    Http::preventStrayRequests();
    Config::set('karoks.processing.enabled', true);
    Config::set('karoks.processing.driver', 'mock');
    Config::set('karoks.usage.default_monthly_limit', 2);
    Config::set('karoks.usage.admin_bypass', true);
    Carbon::setTestNow('2026-07-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('gives users without a subscription the default allowance', function () {
    $user = usageUser();
    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['limit'])->toBe(2)
        ->and($summary['unlimited'])->toBeFalse()
        ->and($summary['remaining'])->toBe(2);
});

it('applies active local subscription plan limits', function () {
    $user = usageUser();
    $plan = createPlanWithProcessingLimit('Premium', 20);
    subscribeUserToPlan($user, $plan);

    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['limit'])->toBe(20)
        ->and($summary['remaining'])->toBe(20);
});

it('uses the default when the plan limit key is missing', function () {
    $user = usageUser();
    $role = Role::firstOrCreate(['name' => 'basic_role', 'guard_name' => 'web']);
    $plan = Plan::create([
        'name' => 'Basic',
        'description' => 'Basic',
        'features' => [],
        'monthly_price' => '5.00',
        'active' => true,
        'role_id' => $role->id,
        'limits' => ['api_keys' => 1],
    ]);
    subscribeUserToPlan($user, $plan);

    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['limit'])->toBe(2)
        ->and($summary['unlimited'])->toBeFalse();
});

it('treats -1 as unlimited allowance', function () {
    $user = usageUser();
    $plan = createPlanWithProcessingLimit('Pro', -1);
    subscribeUserToPlan($user, $plan);

    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['unlimited'])->toBeTrue()
        ->and($summary['limit'])->toBeNull()
        ->and($summary['remaining'])->toBeNull();
});

it('blocks processing when the plan limit is zero', function () {
    $user = usageUser();
    $plan = createPlanWithProcessingLimit('Blocked', 0);
    subscribeUserToPlan($user, $plan);

    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['enabled'])->toBeFalse()
        ->and($summary['limit'])->toBe(0);
});

it('falls back safely for malformed plan limits', function () {
    $user = usageUser();
    $role = Role::firstOrCreate(['name' => 'bad_role', 'guard_name' => 'web']);
    $plan = Plan::create([
        'name' => 'Bad',
        'description' => 'Bad',
        'features' => [],
        'monthly_price' => '5.00',
        'active' => true,
        'role_id' => $role->id,
        'limits' => ['karoks_processing_jobs_monthly' => 'not-a-number'],
    ]);
    subscribeUserToPlan($user, $plan);

    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['limit'])->toBe(2)
        ->and($summary['unlimited'])->toBeFalse();
});

it('falls back safely for non-integer numeric plan limit values', function (mixed $invalidLimit) {
    $user = usageUser();
    $role = Role::firstOrCreate(['name' => 'invalid_limit_role', 'guard_name' => 'web']);
    $plan = Plan::create([
        'name' => 'Invalid Limit '.md5((string) json_encode($invalidLimit)),
        'description' => 'Invalid',
        'features' => [],
        'monthly_price' => '5.00',
        'active' => true,
        'role_id' => $role->id,
        'limits' => ['karoks_processing_jobs_monthly' => $invalidLimit],
    ]);
    subscribeUserToPlan($user, $plan);

    $summary = app(KaraokeUsageService::class)->summary($user);

    expect($summary['limit'])->toBe(2)
        ->and($summary['unlimited'])->toBeFalse();
})->with([
    'float' => [2.9],
    'decimal string' => ['2.9'],
    'scientific string' => ['1e3'],
    'string negative one' => ['-1'],
    'numeric string' => ['5'],
]);

it('allows admin bypass only when configured', function () {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = usageUser();
    $admin->assignRole($adminRole);

    Config::set('karoks.usage.admin_bypass', false);
    expect(app(KaraokeUsageService::class)->summary($admin)['limit'])->toBe(2);

    Config::set('karoks.usage.admin_bypass', true);
    expect(app(KaraokeUsageService::class)->summary($admin)['unlimited'])->toBeTrue();
});

it('blocks new processing starts when the global kill switch is off', function () {
    Config::set('karoks.processing.enabled', false);

    $user = usageUser();
    $project = createUsageProject($user);

    expect(fn () => app(KaraokeProcessingStateService::class)->queueForProcessing($project))
        ->toThrow(KaraokeProcessingDisabledException::class);

    $this->actingAs($user)
        ->post(route('karaoke.projects.process', $project))
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('processing');
});

it('blocks retry dispatch when the kill switch is off', function () {
    $user = usageUser();
    $project = createUsageProject($user, [
        'status' => KaraokeProjectStatus::Failed,
        'error_code' => 'processing_failed',
        'error_message' => 'Processing could not be completed. Please try again.',
    ]);

    Config::set('karoks.processing.enabled', false);

    $this->actingAs($user)
        ->post(route('karaoke.projects.retry', $project))
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('processing');
});

it('keeps status playback download and delete working when the kill switch is off', function () {
    Config::set('karoks.processing.enabled', false);

    $user = usageUser();
    $project = createUsageProject($user, [
        'status' => KaraokeProjectStatus::Completed,
        'transcript' => ['version' => 1, 'lines' => []],
    ]);

    Storage::disk('local')->put('karaoke/'.$user->id.'/'.$project->public_id.'/instrumental.wav', 'audio');
    $project->update([
        'instrumental_path' => 'karaoke/'.$user->id.'/'.$project->public_id.'/instrumental.wav',
        'instrumental_mime_type' => 'audio/wav',
    ]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.status', $project))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('karaoke.projects.source', $project))
        ->assertOk();

    $this->actingAs($user)
        ->delete(route('karaoke.projects.destroy', $project))
        ->assertRedirect(route('karaoke.projects.index'));
});

it('creates one reservation on first start', function () {
    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);

    expect(usageRecordsFor($user)->count())->toBe(1)
        ->and(usageRecordsFor($user)->first()->state)->toBe(KaraokeUsageRecord::STATE_RESERVED);
});

it('does not create a second reservation on duplicate start', function () {
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $state->queueForProcessing($project->fresh());

    expect(usageRecordsFor($user)->count())->toBe(1);
});

it('keeps reservation and queue transition atomic', function () {
    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Queued)
        ->and(usageRecordsFor($user)->where('state', KaraokeUsageRecord::STATE_RESERVED)->count())->toBe(1);
});

it('does not dispatch a job when the allowance is exhausted', function () {
    Config::set('karoks.usage.default_monthly_limit', 1);
    Queue::fake();

    $user = usageUser();
    $first = createUsageProject($user);
    $second = createUsageProject($user);

    $this->actingAs($user)->post(route('karaoke.projects.process', $first))->assertRedirect();
    $this->actingAs($user)->post(route('karaoke.projects.process', $second))
        ->assertRedirect(route('karaoke.projects.show', $second))
        ->assertSessionHasErrors('processing');

    $second->refresh();
    expect($second->status)->toBe(KaraokeProjectStatus::Uploaded);
    Queue::assertPushed(ProcessKaraokeProject::class, 1);
});

it('consumes the reservation when processing begins', function () {
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;

    $state->beginProcessingRun($project->fresh(), $runId);

    $record = usageRecordsFor($user)->first();
    expect($record->state)->toBe(KaraokeUsageRecord::STATE_CONSUMED)
        ->and($record->consumed_at)->not->toBeNull();
});

it('does not enter processing or invoke the processor when queued usage is missing', function () {
    Http::fake();

    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;

    KaraokeUsageRecord::query()->where('karaoke_project_id', $project->id)->delete();

    $job = new ProcessKaraokeProject($project->id, $runId);
    $job->handle(new NeverCalledKaraokeProcessor(), $state);

    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Failed)
        ->and($project->error_code)->toBe('usage_unavailable')
        ->and($project->instrumental_path)->toBeNull()
        ->and($project->transcript)->toBeNull();

    Http::assertNothingSent();
});

it('does not enter processing or invoke the processor when retry usage is missing', function () {
    Http::fake();

    $user = usageUser();
    $project = createUsageProject($user, [
        'status' => KaraokeProjectStatus::Failed,
        'error_code' => 'processing_failed',
        'error_message' => 'Processing could not be completed. Please try again.',
    ]);
    $state = app(KaraokeProcessingStateService::class);

    $state->retryProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;

    KaraokeUsageRecord::query()->where('karaoke_project_id', $project->id)->delete();

    $job = new ProcessKaraokeProject($project->id, $runId);
    $job->handle(new NeverCalledKaraokeProcessor(), $state);

    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Failed)
        ->and($project->error_code)->toBe('usage_unavailable')
        ->and($project->instrumental_path)->toBeNull();

    Http::assertNothingSent();
});

it('does not consume twice when the same job runs again', function () {
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;

    $state->beginProcessingRun($project->fresh(), $runId);
    $state->beginProcessingRun($project->fresh(), $runId);

    expect(usageRecordsFor($user)->where('state', KaraokeUsageRecord::STATE_CONSUMED)->count())->toBe(1);
});

it('does not consume twice when retrying after a retryable failure', function () {
    Http::fake();
    $processor = new FlakyKaraokeProcessor(failuresBeforeSuccess: 1);

    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;
    $job = new ProcessKaraokeProject($project->id, $runId);

    expect(fn () => $job->handle($processor, $state))->toThrow(KaraokeProcessingException::class);
    expect(usageRecordsFor($user)->where('state', KaraokeUsageRecord::STATE_CONSUMED)->count())->toBe(1);

    $job->handle($processor, $state);

    expect(usageRecordsFor($user)->where('state', KaraokeUsageRecord::STATE_CONSUMED)->count())->toBe(1);

    $project->refresh();
    expect($project->status)->toBe(KaraokeProjectStatus::Completed);
});

it('releases allowance when cancelling a queued project', function () {
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $state->cancelProcessing($project->fresh());

    $record = usageRecordsFor($user)->first();
    expect($record->state)->toBe(KaraokeUsageRecord::STATE_RELEASED);

    $summary = app(KaraokeUsageService::class)->summary($user);
    expect($summary['remaining'])->toBe(2);
});

it('does not refund allowance when cancelling during processing', function () {
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;
    $state->beginProcessingRun($project->fresh(), $runId);
    $state->cancelProcessing($project->fresh());

    expect(usageRecordsFor($user)->first()->state)->toBe(KaraokeUsageRecord::STATE_CONSUMED);
    expect(app(KaraokeUsageService::class)->summary($user)['remaining'])->toBe(1);
});

it('does not refund allowance after a processing failure', function () {
    Http::fake();
    $processor = new FlakyKaraokeProcessor(failuresBeforeSuccess: 99);
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;
    $job = new ProcessKaraokeProject($project->id, $runId);

    foreach ([1, 2, 3] as $attempt) {
        try {
            $job->handle($processor, $state);
        } catch (KaraokeProcessingException) {
            expect($processor->attempts)->toBe($attempt);
        }
    }

    $job->failed(new KaraokeProcessingException('Transient processing failure.'));

    expect(usageRecordsFor($user)->first()->state)->toBe(KaraokeUsageRecord::STATE_CONSUMED);
    expect(app(KaraokeUsageService::class)->summary($user)['remaining'])->toBe(1);
});

it('releases allowance when deleting a queued project', function () {
    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    $project->delete();

    expect(usageRecordsFor($user)->first()->state)->toBe(KaraokeUsageRecord::STATE_RELEASED);
});

it('preserves consumed usage when deleting a completed project', function () {
    Http::fake();

    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runUsageProcessingJob($project->fresh());

    $recordId = usageRecordsFor($user)->first()->id;
    $project->fresh()->delete();

    $record = KaraokeUsageRecord::find($recordId);
    expect($record->state)->toBe(KaraokeUsageRecord::STATE_CONSUMED)
        ->and($record->karaoke_project_id)->toBeNull();
});

it('nulls the ledger project reference when the project is deleted', function () {
    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    $project->delete();

    expect(usageRecordsFor($user)->first()->karaoke_project_id)->toBeNull();
});

it('removes ledger records when the user is deleted', function () {
    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);

    $user->forceDelete();

    expect(usageRecordsFor($user)->count())->toBe(0);
});

it('resets available allowance in a new utc month', function () {
    Config::set('karoks.usage.default_monthly_limit', 1);
    $user = usageUser();
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runUsageProcessingJob($project->fresh());

    Carbon::setTestNow('2026-08-01 00:00:00');

    expect(app(KaraokeUsageService::class)->summary($user)['remaining'])->toBe(1);
});

it('does not count released records against allowance', function () {
    Config::set('karoks.usage.default_monthly_limit', 1);
    $user = usageUser();
    $project = createUsageProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $state->cancelProcessing($project->fresh());

    $another = createUsageProject($user);
    $state->queueForProcessing($another);

    expect(usageRecordsFor($user)->where('state', KaraokeUsageRecord::STATE_RESERVED)->count())->toBe(1);
});

it('does not leak internal ids in status usage json', function () {
    $user = usageUser();
    $project = createUsageProject($user);

    $payload = app(KaraokeProcessingStateService::class)->statusPayload($project, $user);

    expect($payload)->toHaveKey('usage')
        ->and(collect($payload['usage'])->keys())->toEqual(collect([
            'metric', 'limit', 'used', 'reserved', 'remaining', 'unlimited', 'reset_at', 'enabled',
        ]))
        ->and(json_encode($payload))->not->toContain('subscription')
        ->and(json_encode($payload))->not->toContain('plan_id');
});

it('forbids cross-user access to usage-aware status', function () {
    $owner = usageUser();
    $other = usageUser();
    $project = createUsageProject($owner);

    $this->actingAs($other)
        ->get(route('karaoke.projects.status', $project))
        ->assertForbidden();
});

it('preserves unrelated plan limits in the sync command', function () {
    $plan = createPlanWithProcessingLimit('Basic', 99);

    Artisan::call('karoks:sync-plan-limits');

    $plan = Plan::query()->find($plan->id);

    expect($plan->limits['api_keys'])->toBe(3)
        ->and($plan->limits['karoks_processing_jobs_monthly'])->toBe(5);
});

it('makes no database changes during sync dry run', function () {
    $plan = createPlanWithProcessingLimit('Premium', 99);
    $before = $plan->limits;

    Artisan::call('karoks:sync-plan-limits', ['--dry-run' => true]);

    $plan->refresh();
    expect($plan->limits)->toBe($before);
});

it('reports unknown configured plan names without modifying other plans', function () {
    Config::set('karoks.usage.plan_name_map.pro', 'Missing Pro Plan');

    $plan = createPlanWithProcessingLimit('Enterprise', 10);
    $before = $plan->limits;

    Artisan::call('karoks:sync-plan-limits');

    expect($plan->fresh()->limits)->toBe($before);
});

it('does not send stripe paddle or provider http requests during usage flows', function () {
    Http::fake();

    $user = usageUser();
    $plan = createPlanWithProcessingLimit('Basic', 5);
    subscribeUserToPlan($user, $plan);
    $project = createUsageProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runUsageProcessingJob($project->fresh());

    Http::assertNothingSent();
});

it('resolves entitlements through the application resolver without remote billing calls', function () {
    $user = usageUser();
    $plan = createPlanWithProcessingLimit('Basic', 7);
    subscribeUserToPlan($user, $plan);

    $resolved = app(KaraokeProcessingEntitlementResolver::class)->resolve($user);

    expect($resolved['limit'])->toBe(7)
        ->and($resolved['unlimited'])->toBeFalse();
});
