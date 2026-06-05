<?php

namespace App\Filament\Resources\BlockedIpResource\Pages;

use App\Filament\Resources\BlockedIpResource;
use App\Models\BlockedIp;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBlockedIps extends ListRecords
{
    protected static string $resource = BlockedIpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('blockIp')
                ->label('Block IP')
                ->icon('heroicon-o-no-symbol')
                ->modalHeading('Block IP Address')
                ->modalWidth('lg')
                ->schema(BlockedIpResource::blockedIpFormSchema())
                ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)
                ->action(function (array $data): void {
                    $record = BlockedIp::create([
                        'ip' => $data['ip'],
                        'reason' => $data['reason'] ?? null,
                        'blocked_by_user_id' => auth()->id(),
                        'blocked_at' => now(),
                    ]);

                    activity('security')
                        ->causedBy(auth()->user())
                        ->performedOn($record)
                        ->withProperties([
                            'ip' => $record->ip,
                            'reason' => $record->reason,
                        ])
                        ->log('ip.blocked');
                })
                ->successNotificationTitle('IP blocked'),
        ];
    }
}
