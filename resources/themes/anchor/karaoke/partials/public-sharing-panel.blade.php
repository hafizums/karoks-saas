@php
    $shareDisclosure = 'Anyone with this link can hear the processed instrumental and view the project title, artist, lyrics, and theme. Source audio and editing remain private.';
@endphp

<div class="p-6 space-y-4 border rounded-xl border-zinc-200 dark:border-zinc-700">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Public sharing</h4>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Create a secure link for guests to play this karaoke project without signing in.
            </p>
        </div>
    </div>

    @if (! $isReadyForPlayback)
        <p class="text-sm text-zinc-600 dark:text-zinc-300" role="status">
            Processing must complete before this project can be shared publicly.
        </p>
    @else
        @error('share')
            <div class="p-3 text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg dark:bg-red-950/30 dark:text-red-100 dark:border-red-900/60" role="alert">
                {{ $message }}
            </div>
        @enderror

        @if ($activeShare)
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                    <dd class="mt-1 font-medium text-emerald-700 dark:text-emerald-300">Active</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Expires</dt>
                    <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
                        {{ $activeShare->expires_at?->format('M j, Y g:i A') ?? 'Never' }}
                    </dd>
                </div>
            </dl>

            @if ($shareUrl)
                <div
                    class="space-y-2"
                    x-data="{
                        copied: false,
                        copyUrl() {
                            const input = this.$refs.shareUrl;
                            input.select();
                            input.setSelectionRange(0, 99999);
                            navigator.clipboard.writeText(input.value).then(() => {
                                this.copied = true;
                                setTimeout(() => { this.copied = false; }, 2000);
                            });
                        }
                    }"
                >
                    <label for="karoks-share-url" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Share link</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input
                            id="karoks-share-url"
                            x-ref="shareUrl"
                            type="text"
                            readonly
                            value="{{ $shareUrl }}"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                        />
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                            @click="copyUrl()"
                        >
                            <span x-text="copied ? 'Copied' : 'Copy link'"></span>
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-amber-700 dark:text-amber-300" role="status">
                    The stored link could not be decrypted. Rotate the link to generate a new one.
                </p>
            @endif

            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Revoking a link stops new playback immediately, but cannot erase audio already downloaded or buffered by a guest.
            </p>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <form method="POST" action="{{ route('karaoke.projects.share.rotate', $project) }}" class="space-y-3 w-full sm:max-w-xl">
                    @csrf
                    <label class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-200">
                        <input
                            type="checkbox"
                            name="sharing_confirmation"
                            value="1"
                            class="mt-1 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-600 dark:bg-zinc-900"
                            required
                        />
                        <span>{{ $shareDisclosure }}</span>
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800">
                        Rotate link
                    </button>
                </form>

                <form
                    method="POST"
                    action="{{ route('karaoke.projects.share.destroy', $project) }}"
                    onsubmit="return confirm('Revoke this public link? Guests will lose access immediately.');"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                        Revoke link
                    </button>
                </form>
            </div>
        @else
            <form method="POST" action="{{ route('karaoke.projects.share.store', $project) }}" class="space-y-4 max-w-xl">
                @csrf

                <div>
                    <label for="expires_in" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Link expiration</label>
                    <select
                        id="expires_in"
                        name="expires_in"
                        class="mt-2 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                        required
                    >
                        @foreach (\App\Enums\KaraokeShareExpirationOption::cases() as $option)
                            <option value="{{ $option->value }}" @selected(old('expires_in', '7d') === $option->value)>
                                {{ $option->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('expires_in')
                        <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-200">
                    <input
                        type="checkbox"
                        name="sharing_confirmation"
                        value="1"
                        class="mt-1 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-600 dark:bg-zinc-900"
                        required
                    />
                    <span>{{ $shareDisclosure }}</span>
                </label>
                @error('sharing_confirmation')
                    <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror

                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">
                    Create public link
                </button>
            </form>
        @endif
    @endif
</div>
