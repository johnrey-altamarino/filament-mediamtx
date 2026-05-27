<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlockedIpResource\Pages;
use App\Models\BlockedIp;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BlockedIpResource extends Resource
{
    protected static ?string $model = BlockedIp::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Control';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Blocked IP';

    protected static ?string $pluralModelLabel = 'Blocked IPs';

    public static function blockedIpFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('ip')
                ->label('IP Address')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->rule('ip'),

            Forms\Components\Textarea::make('reason')
                ->label('Reason')
                ->rows(3)
                ->maxLength(65535),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::blockedIpFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP Address')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(40)
                    ->tooltip(fn (BlockedIp $record) => $record->reason)
                    ->searchable(),

                Tables\Columns\TextColumn::make('blockedBy.username')
                    ->label('Blocked By')
                    ->placeholder('-')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('blocked_at')
                    ->label('Blocked At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('last_7_days')
                    ->label('Last 7 days')
                    ->query(fn ($query) => $query->where('blocked_at', '>=', now()->subDays(7))),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading('Edit Blocked IP')
                    ->modalWidth('lg')
                    ->form(self::blockedIpFormSchema())
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

                Tables\Actions\DeleteAction::make()
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
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label('Unblock selected')
                    ->before(function (Tables\Actions\DeleteBulkAction $action) {
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
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator', 'monitoring']) ?? false;
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
            'index' => Pages\ListBlockedIps::route('/'),
        ];
    }
}
