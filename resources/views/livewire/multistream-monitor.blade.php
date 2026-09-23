@php($visible = $this->currentTiles())
{{-- With 1–2 streams on screen, the tiles stretch to fill the viewport instead of using the 16:9 grid --}}
@php($fill = count($visible) > 0 && count($visible) <= 2 && $this->totalPages() === 1)

<div wire:poll.12s="refresh"
     class="bg-neutral-950 text-neutral-100 p-2 sm:p-3 {{ $fill ? 'h-dvh flex flex-col' : 'min-h-dvh' }}">
    <div class="flex items-center justify-end mb-2 shrink-0">
        <div class="flex items-center gap-3">
            <span class="text-sm text-neutral-400">{{ count($tiles) }} active</span>
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <button type="submit"
                    class="text-xs px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200">
                    Logout
                </button>
            </form>
        </div>
    </div>

    @if (count($visible) === 0)
        <div class="flex items-center justify-center h-[60vh] text-neutral-500">
            No active publishers.
        </div>
    @else
        <div @class([
            'grid gap-2 sm:gap-3',
            'flex-1 min-h-0 grid-cols-1' => $fill,
            'portrait:grid-rows-2 landscape:grid-cols-2' => $fill && count($visible) === 2,
            'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' => ! $fill,
        ])>
            @foreach ($visible as $tile)
                <div wire:key="tile-{{ $tile['name'] }}"
                     x-data="{ expanded: false, copied: false, muted: true }"
                     @keydown.escape.window="expanded = false"
                     :class="expanded
                        ? 'fixed inset-0 z-50 rounded-none border-0'
                        : 'relative rounded-lg border border-neutral-800'"
                     class="flex flex-col overflow-hidden bg-black {{ $fill ? 'min-h-0' : '' }}">
                    {{-- Back button, only visible when expanded to fullscreen --}}
                    <button type="button" x-show="expanded" x-cloak @click="expanded = false"
                        class="absolute top-3 left-3 z-10 flex items-center gap-1 text-sm px-3 py-1.5 rounded bg-neutral-900/80 hover:bg-neutral-800 backdrop-blur">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back
                    </button>

                    <div class="relative bg-black {{ $fill ? 'flex-1 min-h-0' : '' }}"
                         :class="expanded ? 'flex-1 min-h-0' : '{{ $fill ? '' : 'aspect-video' }}'" wire:ignore>
                        {{-- Click-catcher over the iframe (which would otherwise swallow the click): expands this stream to fill the page --}}
                        <button type="button" x-show="!expanded" @click="expanded = true"
                            aria-label="View {{ $tile['name'] }} full page"
                            class="absolute inset-0 z-[1] cursor-zoom-in"></button>
                        <iframe x-ref="frame"
                            src="{{ route('monitor.play', ['stream' => $tile['name'], 'muted' => 'true', 'controls' => 'false', 'autoplay' => 'true']) }}"
                            class="w-full h-full border-0"
                            allow="autoplay"></iframe>
                    </div>

                    <div class="flex items-center gap-2 px-3 py-2 bg-neutral-900">
                        {{-- Path name: click to expand this tile to fill the whole page --}}
                        <button type="button" @click="expanded = true"
                            title="{{ $tile['name'] }}"
                            class="truncate text-sm font-medium text-left px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200 border border-neutral-700">{{ $tile['name'] }}</button>

                        {{-- Copy link (icon) — uses monitorCopy() so it also works over http:// --}}
                        <button type="button" title="Copy link"
                            @click="monitorCopy(@js(route('monitor.play', ['stream' => $tile['name'], 'controls' => 'true']))).then(ok => { if (ok) { copied = true; setTimeout(() => copied = false, 1200) } })"
                            class="shrink-0 p-1.5 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200">
                            <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </button>

                        {{-- Mute / unmute the tile's audio (toggles the same-origin iframe's <video>) --}}
                        <button type="button" :title="muted ? 'Unmute' : 'Mute'"
                            @click="muted = !muted; try { $refs.frame.contentWindow.document.getElementById('myvideo').muted = muted } catch (e) {}"
                            class="shrink-0 p-1.5 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200">
                            {{-- Muted (speaker with X) --}}
                            <svg x-show="muted" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 14l4-4m0 4l-4-4M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/>
                            </svg>
                            {{-- Unmuted (speaker with waves) --}}
                            <svg x-show="!muted" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/>
                            </svg>
                        </button>

                        {{-- Viewer count: bottom right --}}
                        <span class="ml-auto shrink-0 text-xs text-neutral-400">{{ $tile['readers'] }} viewers</span>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($this->totalPages() > 1)
            <div class="flex items-center justify-center gap-4 mt-4">
                <button wire:click="prevPage" @disabled($page <= 1)
                    class="px-3 py-1 rounded bg-neutral-800 disabled:opacity-40">Prev</button>
                <span class="text-sm text-neutral-400">Page {{ $page }} of {{ $this->totalPages() }}</span>
                <button wire:click="nextPage" @disabled($page >= $this->totalPages())
                    class="px-3 py-1 rounded bg-neutral-800 disabled:opacity-40">Next</button>
            </div>
        @endif
    @endif
</div>
