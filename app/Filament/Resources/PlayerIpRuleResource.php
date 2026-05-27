<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlayerIpRuleResource\Pages;
use App\Models\PlayerIpRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlayerIpRuleResource extends Resource
{
    protected static ?string $model = PlayerIpRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Control';

    protected static ?string $navigationLabel = 'Player IP Rules';

    protected static ?string $modelLabel = 'IP Rule';

    protected static ?string $pluralModelLabel = 'IP Rules';

    protected static ?int $navigationSort = 3;

    public static function ipRuleFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('ip')
                ->label('IP Address')
                ->required()
                ->maxLength(45)
                ->unique(table: PlayerIpRule::class, ignoreRecord: true)
                ->placeholder('192.168.1.1 or 0.0.0.0')
                ->helperText('Use 0.0.0.0 in whitelist mode to allow all IPs.'),

            Forms\Components\TextInput::make('label')
                ->label('Label')
                ->maxLength(255)
                ->placeholder('e.g. Office network'),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::ipRuleFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP Address')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->label('Label')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit IP Rule')
                    ->modalWidth('lg')
                    ->form(self::ipRuleFormSchema())
                    ->fillForm(fn (PlayerIpRule $record) => [
                        'ip' => $record->ip,
                        'label' => $record->label,
                    ])
                    ->action(fn (PlayerIpRule $record, array $data) => $record->update([
                        'ip' => $data['ip'],
                        'label' => $data['label'] ?? null,
                    ]))
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlayerIpRules::route('/'),
        ];
    }
}
