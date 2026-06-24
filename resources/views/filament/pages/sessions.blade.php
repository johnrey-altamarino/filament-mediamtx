<x-filament-panels::page>
    <div wire:poll.10s="refreshSessions">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
