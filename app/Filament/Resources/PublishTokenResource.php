<?php

namespace App\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\PublishTokenResource\Pages\ListPublishTokens;
use App\Filament\Resources\PublishTokenResource\Pages;
use App\Models\PublishToken;
use App\Services\MediamtxJwtIssuer;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Component as LivewireComponent;

class PublishTokenResource extends Resource
{
    protected static ?string $model = PublishToken::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Publish Token Generator';

    public static function publishTokenFormSchema(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('path')
                ->label('MediaMTX Path')
                ->helperText('Example: live/stream1')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Toggle::make('is_enabled')
                ->label('Enabled')
                ->default(true),

            Textarea::make('notes')
                ->rows(3)
                ->maxLength(65535),

            // Only used on edit modal to rotate token
            Toggle::make('regenerate_token')
                ->label('Regenerate token (rotate)')
                ->default(false)
                ->dehydrated(false)
                ->visible(fn ($livewire) => method_exists($livewire, 'getRecord') && (bool) $livewire->getRecord()),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::publishTokenFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_enabled')->label('Status')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('path')->label('Path')->searchable()->sortable(),
                TextColumn::make('last_generated_at')->label('Generated')->dateTime('M d, Y h:i:s A')->timezone('Asia/Manila')->sortable(),
                TextColumn::make('createdBy.username')->label('Created By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('copy_token')
                    ->label('Copy Token')
                    ->icon('heroicon-o-clipboard-document')
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false)
                    ->action(function (PublishToken $record, LivewireComponent $livewire) {
                        // Optional: enforce enabled
                        if (! $record->is_enabled) {
                            Notification::make()
                                ->title('Token is disabled')
                                ->danger()
                                ->send();

                            return;
                        }

                        // Ensure token exists (issue if missing)
                        if (! filled($record->token)) {
                            $issuer = app(MediamtxJwtIssuer::class);
                            $issued = $issuer->issuePublishToken($record->path, noExpiry: true);

                            $record->update([
                                'token' => $issued['token'],
                                'last_generated_at' => now(),
                            ]);

                            activity('security')
                                ->causedBy(auth()->user())
                                ->performedOn($record)
                                ->withProperties(['path' => $record->path])
                                ->log('publish_token.issued');
                        }

                        // Dispatch browser event to copy
                        $livewire->dispatch('clipboard-copy', text: 'Bearer '.$record->token);

                        activity('security')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties(['path' => $record->path])
                            ->log('publish_token.copied');

                        Notification::make()
                            ->title('Token copied to clipboard')
                            ->success()
                            ->send();
                    }),
                Action::make('rotate')
                    ->label('Rotate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Rotate publish token?')
                    ->action(function (PublishToken $record) {
                        $issuer = app(MediamtxJwtIssuer::class);
                        $issued = $issuer->issuePublishToken($record->path, noExpiry: true);

                        $record->update([
                            'token' => $issued['token'],
                            'last_generated_at' => now(),
                        ]);

                        activity('security')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties(['path' => $record->path])
                            ->log('publish_token.rotated');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                // Edit modal
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit Publish Token')
                    ->modalWidth('lg')
                    ->schema(self::publishTokenFormSchema())
                    ->fillForm(fn (PublishToken $record) => [
                        'name' => $record->name,
                        'path' => $record->path,
                        'is_enabled' => $record->is_enabled,
                        'notes' => $record->notes,
                    ])
                    ->action(function (PublishToken $record, array $data) {
                        $record->update([
                            'name' => $data['name'],
                            'path' => $data['path'],
                            'is_enabled' => (bool) $data['is_enabled'],
                            'notes' => $data['notes'] ?? null,
                        ]);

                        if (! empty($data['regenerate_token'])) {
                            $issuer = app(MediamtxJwtIssuer::class);
                            $issued = $issuer->issuePublishToken($record->path, noExpiry: true);

                            $record->update([
                                'token' => $issued['token'],
                                'last_generated_at' => now(),
                            ]);

                            activity('security')
                                ->causedBy(auth()->user())
                                ->performedOn($record)
                                ->withProperties(['path' => $record->path])
                                ->log('publish_token.rotated');
                        } else {
                            activity('security')
                                ->causedBy(auth()->user())
                                ->performedOn($record)
                                ->withProperties(['path' => $record->path])
                                ->log('publish_token.updated');
                        }
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                DeleteAction::make()
                    ->label('Delete')
                    ->before(function (PublishToken $record) {
                        activity('security')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties(['path' => $record->path])
                            ->log('publish_token.deleted');
                    })
                    ->visible(fn () => auth()->user()?->hasRole('super-admin') ?? false),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super-admin') ?? false),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublishTokens::route('/'),
        ];
    }
}
