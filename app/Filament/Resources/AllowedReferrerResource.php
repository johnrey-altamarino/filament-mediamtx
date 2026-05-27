<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AllowedReferrerResource\Pages;
use App\Models\AllowedReferrer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AllowedReferrerResource extends Resource
{
    protected static ?string $model = AllowedReferrer::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Allowed Referrers';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationGroup = 'Control';

    public static function referrerFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('pattern')
                ->label('Domain or regex pattern')
                ->helperText('If regex is enabled, use a PHP regex delimiter, e.g. /^https:\\/\\/([a-z0-9-]+\\.)*armtech\\.one(\\/|$)/i')
                ->required()
                ->maxLength(500)
                ->unique(ignoreRecord: true),

            Forms\Components\Toggle::make('is_regex')
                ->label('Use regex')
                ->default(false),

            Forms\Components\Toggle::make('is_enabled')
                ->label('Enabled')
                ->default(true),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::referrerFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('pattern')->label('Domain')->limit(60)->tooltip(fn (AllowedReferrer $record) => $record->pattern)->searchable(),
                Tables\Columns\IconColumn::make('is_regex')->label('Regex')->boolean(),
                Tables\Columns\TextColumn::make('createdBy.username')->label('Created By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime('Y-m-d H:i:s')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_enabled')->label('Status')->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit Allowed Referrer')
                    ->modalWidth('xl')
                    ->form(self::referrerFormSchema())
                    ->fillForm(fn (AllowedReferrer $record) => [
                        'name' => $record->name,
                        'pattern' => $record->pattern,
                        'is_regex' => $record->is_regex,
                        'is_enabled' => $record->is_enabled,
                    ])
                    ->action(function (AllowedReferrer $record, array $data): void {
                        $record->update([
                            'name' => $data['name'],
                            'pattern' => $data['pattern'],
                            'is_regex' => (bool) $data['is_regex'],
                            'is_enabled' => (bool) $data['is_enabled'],
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
                            ->log('referrer.updated');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                Tables\Actions\DeleteAction::make()
                    ->label('Remove')
                    ->before(function (AllowedReferrer $record) {
                        activity('security')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties([
                                'name' => $record->name,
                                'pattern' => $record->pattern,
                            ])
                            ->log('referrer.removed');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label('Remove selected')
                    ->before(function (Tables\Actions\DeleteBulkAction $action) {
                        $records = $action->getRecords();

                        activity('security')
                            ->causedBy(auth()->user())
                            ->withProperties([
                                'count' => $records->count(),
                                'patterns' => $records->pluck('pattern')->values()->all(),
                            ])
                            ->log('referrer.bulk_removed');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ]);
    }

    // Access control
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAllowedReferrers::route('/'),
        ];
    }
}
