<?php

namespace App\Http\Controllers;

use App\Enums\KaraokeProjectStatus;
use App\Exceptions\KaraokeProcessingDisabledException;
use App\Exceptions\KaraokeProcessingGateException;
use App\Exceptions\KaraokeUsageLimitExceededException;
use App\Http\Requests\StartKaraokeProcessingRequest;
use App\Models\KaraokeProject;
use App\Support\KaraokeProcessingStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class KaraokeProjectProcessingController extends Controller
{
    public function process(StartKaraokeProcessingRequest $request, KaraokeProject $karaokeProject, KaraokeProcessingStateService $stateService): RedirectResponse|JsonResponse
    {
        $this->authorize('process', $karaokeProject);

        if (in_array($karaokeProject->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
            return redirect()
                ->route('karaoke.projects.show', $karaokeProject)
                ->with('success', 'Processing is already in progress.');
        }

        if (! in_array($karaokeProject->status, [KaraokeProjectStatus::Uploaded, KaraokeProjectStatus::Cancelled], true)) {
            abort(422, 'Processing can only be started from an uploaded project.');
        }

        try {
            $stateService->queueForProcessing($karaokeProject, $request->providerConsentAccepted());
        } catch (KaraokeProcessingDisabledException) {
            return redirect()
                ->route('karaoke.projects.show', $karaokeProject)
                ->withErrors(['processing' => 'Processing is temporarily unavailable. Please try again later.']);
        } catch (KaraokeProcessingGateException $exception) {
            return redirect()
                ->route('karaoke.projects.show', $karaokeProject)
                ->withErrors(['processing' => $exception->getMessage()]);
        } catch (KaraokeUsageLimitExceededException) {
            return redirect()
                ->route('karaoke.projects.show', $karaokeProject)
                ->withErrors(['processing' => 'Your monthly processing allowance has been reached. Upgrade your plan or wait until your allowance resets.']);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Processing has been queued.');
    }

    public function cancel(KaraokeProject $karaokeProject, KaraokeProcessingStateService $stateService): RedirectResponse|JsonResponse
    {
        $this->authorize('cancel', $karaokeProject);

        if (! $stateService->cancelProcessing($karaokeProject)) {
            abort(422, 'Processing cannot be cancelled in its current state.');
        }

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Processing was cancelled.');
    }

    public function retry(KaraokeProject $karaokeProject, KaraokeProcessingStateService $stateService): RedirectResponse|JsonResponse
    {
        if ($karaokeProject->status === KaraokeProjectStatus::Failed && ! $stateService->isRetryable($karaokeProject)) {
            abort(422, 'This project cannot be retried.');
        }

        $this->authorize('retry', $karaokeProject);

        if (in_array($karaokeProject->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
            return redirect()
                ->route('karaoke.projects.show', $karaokeProject)
                ->with('success', 'Processing retry is already in progress.');
        }

        try {
            $stateService->retryProcessing($karaokeProject);
        } catch (KaraokeProcessingDisabledException) {
            return redirect()
                ->route('karaoke.projects.show', $karaokeProject)
                ->withErrors(['processing' => 'Processing is temporarily unavailable. Please try again later.']);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Processing retry has been queued.');
    }

    public function status(KaraokeProject $karaokeProject, KaraokeProcessingStateService $stateService): JsonResponse
    {
        $this->authorize('viewStatus', $karaokeProject);

        $karaokeProject->refresh();

        return response()->json($stateService->statusPayload($karaokeProject, auth()->user()));
    }
}
