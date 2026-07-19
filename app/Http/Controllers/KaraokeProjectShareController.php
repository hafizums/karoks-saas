<?php

namespace App\Http\Controllers;

use App\Http\Requests\RotateKaraokeShareRequest;
use App\Http\Requests\StoreKaraokeShareRequest;
use App\Models\KaraokeProject;
use App\Support\Karaoke\KaraokeProjectShareService;
use Illuminate\Http\RedirectResponse;

class KaraokeProjectShareController extends Controller
{
    public function store(
        StoreKaraokeShareRequest $request,
        KaraokeProject $karaokeProject,
        KaraokeProjectShareService $shareService,
    ): RedirectResponse {
        $this->authorize('share', $karaokeProject);

        $shareService->createShare(
            $karaokeProject,
            $request->user(),
            $request->expirationOption(),
        );

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Public sharing link created.');
    }

    public function rotate(
        RotateKaraokeShareRequest $request,
        KaraokeProject $karaokeProject,
        KaraokeProjectShareService $shareService,
    ): RedirectResponse {
        $this->authorize('rotateShare', $karaokeProject);

        $shareService->rotateShare($karaokeProject, $request->user());

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Public sharing link rotated. Previous links no longer work.');
    }

    public function destroy(
        KaraokeProject $karaokeProject,
        KaraokeProjectShareService $shareService,
    ): RedirectResponse {
        $this->authorize('revokeShare', $karaokeProject);

        $shareService->revokeShare($karaokeProject, auth()->user());

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Public sharing link revoked.');
    }
}
