<div wire:poll.12s="refresh" class="min-h-screen bg-neutral-950 text-neutral-100 p-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Multistream Monitor</h1>
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

    @php($visible = $this->currentTiles())

    @if (count($visible) === 0)
        <div class="flex items-center justify-center h-[60vh] text-neutral-500">
            No active publishers.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($visible as $tile)
                <div wire:key="tile-{{ $tile['name'] }}"
                     class="rounded-lg overflow-hidden bg-black border border-neutral-800">
                    <div class="flex items-center justify-between px-3 py-2 bg-neutral-900">
                        <span class="truncate text-sm font-medium" title="{{ $tile['name'] }}">{{ $tile['name'] }}</span>
                        <span class="text-xs text-neutral-400">{{ $tile['readers'] }} viewers</span>
                    </div>
                    <div class="aspect-video bg-black" wire:ignore>
                        <iframe
                            src="{{ route('monitor.play', ['stream' => $tile['name'], 'muted' => 'true', 'controls' => 'false', 'autoplay' => 'true']) }}"
                            class="w-full h-full border-0"
                            allow="autoplay"></iframe>
                    </div>
                    <div class="flex gap-2 px-3 py-2 bg-neutral-900">
                        <a href="{{ route('monitor.play', ['stream' => $tile['name'], 'controls' => 'true']) }}"
                           target="_blank" rel="noopener"
                           class="text-xs px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700">Open</a>
                        <button type="button"
                            onclick="navigator.clipboard.writeText('{{ route('monitor.play', ['stream' => $tile['name'], 'controls' => 'true']) }}'); this.textContent='Copied'; setTimeout(() => this.textContent='Copy', 1200);"
                            class="text-xs px-2 py-1 rounded bg-neutral-800 hover:bg-neutral-700">Copy</button>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($this->totalPages() > 1)
            <div class="flex items-center justify-center gap-4 mt-6">
                <button wire:click="prevPage" @disabled($page <= 1)
                    class="px-3 py-1 rounded bg-neutral-800 disabled:opacity-40">Prev</button>
                <span class="text-sm text-neutral-400">Page {{ $page }} of {{ $this->totalPages() }}</span>
                <button wire:click="nextPage" @disabled($page >= $this->totalPages())
                    class="px-3 py-1 rounded bg-neutral-800 disabled:opacity-40">Next</button>
            </div>
        @endif
    @endif
</div>
