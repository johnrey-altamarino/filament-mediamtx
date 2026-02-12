<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublishTokenResource\Pages;
use App\Models\PublishToken;
use App\Services\MediamtxJwtIssuer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PublishTokenResource extends Resource
{
    protected static ?string $model = PublishToken::class;
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'Publish Tokens';

    public static function publishTokenFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('path')
                ->label('MediaMTX Path')
                ->helperText('Example: live/stream1')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Forms\Components\Toggle::make('is_enabled')
                ->label('Enabled')
                ->default(true),

            Forms\Components\Textarea::make('notes')
                ->rows(3)
                ->maxLength(65535),

            // Only used on edit modal to rotate token
            Forms\Components\Toggle::make('regenerate_token')
                ->label('Regenerate token (rotate)')
                ->default(false)
                ->dehydrated(false)
                ->visible(fn ($livewire) => method_exists($livewire, 'getRecord') && (bool) $livewire->getRecord()),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::publishTokenFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_enabled')->label('Enabled')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('path')->label('Path')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('last_generated_at')->label('Generated')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('createdBy.username')->label('Created By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('rotate')
                ->label('Rotate')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Rotate publish token?')
                ->action(function (\App\Models\PublishToken $record) {
                    $issuer = app(\App\Services\MediamtxJwtIssuer::class);
                    $issued = $issuer->issuePublishToken($record->path, noExpiry: true);

                    $record->update([
                        'token' => $issued['token'],
                        'last_generated_at' => now()->setTimezone('Asia/Manila'),
                    ]);

                    activity('security')
                        ->causedBy(auth()->user())
                        ->performedOn($record)
                        ->withProperties(['path' => $record->path])
                        ->log('publish_token.rotated');
                })
                ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                // Get Token modal
                Tables\Actions\Action::make('get_token')
                    ->label('Get Token')
                    ->icon('heroicon-o-clipboard-document')
                    ->modalHeading('OBS Bearer Token')
                    ->modalWidth('2xl')
                    ->modalContent(function (PublishToken $record) {
                        $token = $record->token;

                        // Render a minimal HTML chunk (no infolist arrays)
                        return view('filament.publish-token-modal', [
                            'path' => $record->path,
                            'token' => $token,
                        ]);
                    }),

                // Edit modal
                Tables\Actions\Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit Publish Token')
                    ->modalWidth('lg')
                    ->form(self::publishTokenFormSchema())
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

                Tables\Actions\DeleteAction::make()
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
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
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
            'index' => Pages\ListPublishTokens::route('/'),
        ];
    }
}
