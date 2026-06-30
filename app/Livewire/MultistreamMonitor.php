<?php

namespace App\Livewire;

use App\Services\ActivePublishPaths;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.monitor')]
class MultistreamMonitor extends Component
{
    public const PER_PAGE = 6;

    public int $page = 1;

    /** @var array<int, array{name: string, source_type: string, tracks: array, readers: int}> */
    public array $tiles = [];

    public function mount(): void
    {
        $this->refresh();
    }

    /** Reload the active-stream list and keep the page within bounds. */
    public function refresh(): void
    {
        $this->tiles = app(ActivePublishPaths::class)->get();

        $this->page = max(1, min($this->page, $this->totalPages()));
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil(count($this->tiles) / self::PER_PAGE));
    }

    /** @return array<int, array{name: string, source_type: string, tracks: array, readers: int}> */
    public function currentTiles(): array
    {
        return array_slice($this->tiles, ($this->page - 1) * self::PER_PAGE, self::PER_PAGE);
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages()) {
            $this->page++;
        }
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function render()
    {
        return view('livewire.multistream-monitor');
    }
}
