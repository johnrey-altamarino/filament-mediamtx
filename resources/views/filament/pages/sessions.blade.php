<x-filament-panels::page>
    <div wire:poll.5s="refreshSessions">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
