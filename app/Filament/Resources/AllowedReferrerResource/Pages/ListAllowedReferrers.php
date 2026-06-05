<?php

namespace App\Filament\Resources\AllowedReferrerResource\Pages;

use App\Filament\Resources\AllowedReferrerResource;
use App\Models\AllowedReferrer;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAllowedReferrers extends ListRecords
{
    protected static string $resource = AllowedReferrerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addReferrer')
                ->label('Add Referrer')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add Allowed Referrer')
                ->modalWidth('xl')
                ->schema(AllowedReferrerResource::referrerFormSchema())
                ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)
                ->action(function (array $data): void {
                    $record = AllowedReferrer::create([
                        'name' => $data['name'],
                        'pattern' => $data['pattern'],
                        'is_regex' => (bool) $data['is_regex'],
                        'is_enabled' => (bool) $data['is_enabled'],
                        'created_by_user_id' => auth()->id(),
                    ]);

                    activity('security')
                        ->causedBy(auth()->user())
                        ->performedOn($record)
                        ->withProperties([
                            'name' => $record->name,
                            'pattern' => $record->pattern,
                            'is_regex' => $record->is_regex,
                            'is_enabled' => $record->is_enabled,
                        ])
                        ->log('referrer.added');
                })
                ->successNotificationTitle('Referrer added'),
        ];
    }
}
