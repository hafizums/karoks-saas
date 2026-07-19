@php
    $embedDisclosure = 'Only the origins you list below may frame this player in an iframe. Anyone who can read the iframe source can obtain the bearer share URL. Revoking or rotating the share link disables previously copied embed code. Audio already buffered by a guest cannot be recalled.';
    $allowedOriginsText = old(
        'embed_allowed_origins',
        $activeShare?->embed_allowed_origins
            ? implode("\n", $activeShare->embed_allowed_origins)
            : '',
    );
@endphp

<div class="p-6 space-y-4 border rounded-xl border-zinc-200 dark:border-zinc-700">
    <div>
        <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Controlled embedding</h4>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Allow approved external sites to frame this shared player in an iframe.
        </p>
    </div>

    @if (! $activeShare)
        <p class="text-sm text-zinc-600 dark:text-zinc-300" role="status">
            Create an active public share link before configuring embedding.
        </p>
    @else
        @error('embed')
            <div class="p-3 text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg dark:bg-red-950/30 dark:text-red-100 dark:border-red-900/60" role="alert">
                {{ $message }}
            </div>
        @enderror

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Embedding</dt>
                <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
                    {{ $activeShare->embedding_enabled ? 'Enabled' : 'Disabled' }}
                </dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Last updated</dt>
                <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
                    {{ $activeShare->embedding_updated_at?->format('M j, Y g:i A') ?? 'Never' }}
                </dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('karaoke.projects.share.embed.update', $project) }}" class="space-y-4 max-w-xl">
            @csrf
            @method('PATCH')

            <div>
                <label for="embed_allowed_origins" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Allowed origins</label>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Enter one origin per line, such as https://example.com</p>
                <textarea
                    id="embed_allowed_origins"
                    name="embed_allowed_origins"
                    rows="4"
                    required
                    class="mt-2 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                >{{ $allowedOriginsText }}</textarea>
                @error('embed_allowed_origins')
                    <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-200">
                <input
                    type="checkbox"
                    name="embedding_confirmation"
                    value="1"
                    class="mt-1 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-600 dark:bg-zinc-900"
                    required
                />
                <span>{{ $embedDisclosure }}</span>
            </label>
            @error('embedding_confirmation')
                <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror

            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">
                {{ $activeShare->embedding_enabled ? 'Update embedding' : 'Enable embedding' }}
            </button>
        </form>

        @if ($activeShare->embedding_enabled)
            <form method="POST" action="{{ route('karaoke.projects.share.embed.destroy', $project) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                    Disable embedding
                </button>
            </form>
        @endif

        @if ($embedIframeMarkup)
            <div
                class="space-y-2"
                x-data="{
                    copied: false,
                    copyEmbed() {
                        navigator.clipboard.writeText(this.$refs.embedCode.value).then(() => {
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2000);
                        });
                    }
                }"
            >
                <label for="karoks-embed-code" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Iframe embed code</label>
                <textarea
                    id="karoks-embed-code"
                    x-ref="embedCode"
                    readonly
                    rows="4"
                    class="block w-full max-w-full overflow-x-auto rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                >{{ $embedIframeMarkup }}</textarea>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                        @click="copyEmbed()"
                    >
                        <span x-text="copied ? 'Copied' : 'Copy embed code'"></span>
                    </button>
                    @if ($embedUrl)
                        <a
                            href="{{ $embedUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100"
                        >
                            Preview embed page
                        </a>
                    @endif
                </div>
            </div>
        @elseif ($activeShare->embedding_enabled)
            <p class="text-sm text-amber-700 dark:text-amber-300" role="status">
                Embed code is unavailable because the stored share token could not be decrypted. Rotate the share link to regenerate it.
            </p>
        @endif
    @endif
</div>
