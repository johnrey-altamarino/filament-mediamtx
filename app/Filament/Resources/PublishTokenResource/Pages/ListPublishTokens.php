<?php

namespace App\Filament\Resources\PublishTokenResource\Pages;

use App\Filament\Resources\PublishTokenResource;
use App\Models\PublishToken;
use App\Services\MediamtxJwtIssuer;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPublishTokens extends ListRecords
{
    protected static string $resource = PublishTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createPublishToken')
                ->label('New Publish Token')
                ->icon('heroicon-o-plus')
                ->modalHeading('Create Publish Token')
                ->modalWidth('lg')
                ->form(PublishTokenResource::publishTokenFormSchema())
                ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)
                ->action(function (array $data) {
                    $issuer = app(MediamtxJwtIssuer::class);
                    $issued = $issuer->issuePublishToken($data['path'], noExpiry: true);

                    $record = PublishToken::create([
                        'name' => $data['name'],
                        'path' => $data['path'],
                        'is_enabled' => (bool) $data['is_enabled'],
                        'notes' => $data['notes'] ?? null,
                        'token' => $issued['token'],
                        'created_by_user_id' => auth()->id(),
                        'last_generated_at' => now(),
                    ]);

                    activity('security')
                        ->causedBy(auth()->user())
                        ->performedOn($record)
                        ->withProperties(['path' => $record->path])
                        ->log('publish_token.created');
                })
                ->successNotificationTitle('Publish token created'),
        ];
    }
}
