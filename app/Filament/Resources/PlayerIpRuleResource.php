<?php

namespace App\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\PlayerIpRuleResource\Pages\ListPlayerIpRules;
use App\Filament\Resources\PlayerIpRuleResource\Pages;
use App\Models\PlayerIpRule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlayerIpRuleResource extends Resource
{
    protected static ?string $model = PlayerIpRule::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = 'Security Control';

    protected static ?string $navigationLabel = 'Player IP Rules';

    protected static ?string $modelLabel = 'IP Rule';

    protected static ?string $pluralModelLabel = 'IP Rules';

    protected static ?int $navigationSort = 3;

    public static function ipRuleFormSchema(): array
    {
        return [
            TextInput::make('ip')
                ->label('IP Address')
                ->required()
                ->maxLength(45)
                ->unique(table: PlayerIpRule::class, ignoreRecord: true)
                ->placeholder('192.168.1.1 or 0.0.0.0')
                ->helperText('Use 0.0.0.0 in whitelist mode to allow all IPs.'),

            TextInput::make('label')
                ->label('Label')
                ->maxLength(255)
                ->placeholder('e.g. Office network'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::ipRuleFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip')
                    ->label('IP Address')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('label')
                    ->label('Label')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit IP Rule')
                    ->modalWidth('lg')
                    ->schema(self::ipRuleFormSchema())
                    ->fillForm(fn (PlayerIpRule $record) => [
                        'ip' => $record->ip,
                        'label' => $record->label,
                    ])
                    ->action(fn (PlayerIpRule $record, array $data) => $record->update([
                        'ip' => $data['ip'],
                        'label' => $data['label'] ?? null,
                    ]))
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
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
            'index' => ListPlayerIpRules::route('/'),
        ];
    }
}
