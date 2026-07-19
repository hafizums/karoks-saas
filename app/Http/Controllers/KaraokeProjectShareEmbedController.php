<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateKaraokeShareEmbedRequest;
use App\Models\KaraokeProject;
use App\Support\Karaoke\KaraokeProjectShareService;
use Illuminate\Http\RedirectResponse;

class KaraokeProjectShareEmbedController extends Controller
{
    public function update(
        UpdateKaraokeShareEmbedRequest $request,
        KaraokeProject $karaokeProject,
        KaraokeProjectShareService $shareService,
    ): RedirectResponse {
        $this->authorize('manageEmbed', $karaokeProject);

        $shareService->enableEmbedding(
            $karaokeProject,
            $request->user(),
            $request->originInputs(),
        );

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Embedding settings updated.');
    }

    public function destroy(
        KaraokeProject $karaokeProject,
        KaraokeProjectShareService $shareService,
    ): RedirectResponse {
        $this->authorize('manageEmbed', $karaokeProject);

        $shareService->disableEmbedding($karaokeProject, auth()->user());

        return redirect()
            ->route('karaoke.projects.show', $karaokeProject)
            ->with('success', 'Embedding disabled.');
    }
}
