<?php

namespace App\Services;

use Throwable;

class ActivePublishPaths
{
    public function __construct(private readonly MediamtxApi $api) {}

    /**
     * Live publishers only: a path is "active" when MediaMTX reports it ready
     * and it has a source (an actual publisher). Returns a clean, sorted list.
     *
     * @return array<int, array{name: string, source_type: string, tracks: array, readers: int}>
     */
    public function get(): array
    {
        try {
            $items = data_get($this->api->listPaths(), 'items', []);
        } catch (Throwable $e) {
            return [];
        }

        $active = [];
        foreach ($items as $item) {
            if (data_get($item, 'ready') !== true || data_get($item, 'source') === null) {
                continue;
            }

            $active[] = [
                'name' => (string) data_get($item, 'name', ''),
                'source_type' => (string) data_get($item, 'source.type', ''),
                'tracks' => (array) data_get($item, 'tracks', []),
                'readers' => count((array) data_get($item, 'readers', [])),
            ];
        }

        usort($active, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $active;
    }
}
