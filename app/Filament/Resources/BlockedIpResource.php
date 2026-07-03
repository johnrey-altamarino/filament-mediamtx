<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlockedIpResource\Pages\ListBlockedIps;
use App\Models\BlockedIp;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BlockedIpResource extends Resource
{
    protected static ?string $model = BlockedIp::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|\UnitEnum|null $navigationGroup = 'Security Control';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Blocked IP';

    protected static ?string $pluralModelLabel = 'Blocked IPs';

    public static function blockedIpFormSchema(): array
    {
        return [
            TextInput::make('ip')
                ->label('IP Address')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->rule('ip'),

            Textarea::make('reason')
                ->label('Reason')
                ->rows(3)
                ->maxLength(65535),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::blockedIpFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip')
                    ->label('IP Address')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(40)
                    ->tooltip(fn (BlockedIp $record) => $record->reason)
                    ->searchable(),

                TextColumn::make('blockedBy.username')
                    ->label('Blocked By')
                    ->placeholder('-')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('blocked_at')
                    ->label('Blocked At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('last_7_days')
                    ->label('Last 7 days')
                    ->query(fn ($query) => $query->where('blocked_at', '>=', now()->subDays(7))),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit Blocked IP')
                    ->modalWidth('lg')
                    ->schema(self::blockedIpFormSchema())
                    ->fillForm(fn (BlockedIp $record) => [
                        'ip' => $record->ip,
                        'reason' => $record->reason,
                    ])
                    ->action(function (BlockedIp $record, array $data): void {
                        $record->update([
                            'ip' => $data['ip'],
                            'reason' => $data['reason'] ?? null,
                        ]);

                        activity('security')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties([
                                'ip' => $record->ip,
                                'reason' => $record->reason,
                            ])
                            ->log('ip.updated');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                DeleteAction::make()
                    ->label('Unblock')
                    ->modalHeading('Unblock IP')
                    ->before(function (BlockedIp $record) {
                        activity('security')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties([
                                'ip' => $record->ip,
                                'reason' => $record->reason,
                            ])
                            ->log('ip.unblocked');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Unblock selected')
                    ->before(function (DeleteBulkAction $action) {
                        $records = $action->getRecords();

                        activity('security')
                            ->causedBy(auth()->user())
                            ->withProperties([
                                'count' => $records->count(),
                                'ips' => $records->pluck('ip')->values()->all(),
                            ])
                            ->log('ip.bulk_unblocked');
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ]);
    }

    // Role access
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canCreate(): bool
    {
        // We create via modal in List page header, but keep policy consistent
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

    // ✅ index only
    public static function getPages(): array
    {
        return [
            'index' => ListBlockedIps::route('/'),
        ];
    }
}
