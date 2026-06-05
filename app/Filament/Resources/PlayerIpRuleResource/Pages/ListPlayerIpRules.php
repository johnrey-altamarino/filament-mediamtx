<?php

namespace App\Filament\Resources\PlayerIpRuleResource\Pages;

use App\Filament\Resources\PlayerIpRuleResource;
use App\Models\PlayerIpRule;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPlayerIpRules extends ListRecords
{
    protected static string $resource = PlayerIpRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addRule')
                ->label('Add IP Rule')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add IP Rule')
                ->modalWidth('lg')
                ->schema(PlayerIpRuleResource::ipRuleFormSchema())
                ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)
                ->action(function (array $data): void {
                    PlayerIpRule::create([
                        'ip' => $data['ip'],
                        'label' => $data['label'] ?? null,
                    ]);
                }),
        ];
    }
}
