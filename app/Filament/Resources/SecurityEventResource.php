<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class SecurityEventResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Security Events';
    protected static ?string $navigationGroup = 'Control';
    protected static ?string $slug = 'security-events';
    protected static ?int $navigationSort = 25;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('log_name', 'security');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('properties.ip')
                    ->label('IP')
                    ->searchable(),

                Tables\Columns\TextColumn::make('properties.path')
                    ->label('Path')
                    ->searchable(),

                Tables\Columns\TextColumn::make('properties.referer')
                    ->label('Referer')
                    ->searchable()
                    ->limit(40),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('range')
                    ->label('Time Range')
                    ->options([
                        '1h' => 'Last 1 hour',
                        '12h' => 'Last 12 hours',
                        '24h' => 'Last 24 hours',
                        '7d' => 'Last 7 days',
                    ])
                    ->default('12h')
                    ->query(function (Builder $query, array $data) {
                        $v = $data['value'] ?? '12h';
                        $since = match ($v) {
                            '1h' => now()->subHour(),
                            '24h' => now()->subDay(),
                            '7d' => now()->subDays(7),
                            default => now()->subHours(12),
                        };

                        return $query->where('created_at', '>=', $since);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('block_ip')
                    ->label('Block IP')
                    ->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->visible(fn (Activity $record) => filled(data_get($record->properties, 'ip'))
                        && (auth()->user()?->hasAnyRole(['super-admin','moderator']) ?? false))
                    ->action(function (Activity $record) {
                        $ip = (string) data_get($record->properties, 'ip');

                        \App\Models\BlockedIp::firstOrCreate(
                            ['ip' => $ip],
                            [
                                'reason' => 'Blocked from Security Events',
                                'blocked_by_user_id' => auth()->id(),
                                'blocked_at' => now(),
                            ]
                        );

                        activity('security')
                            ->causedBy(auth()->user())
                            ->withProperties(['ip' => $ip])
                            ->log('ip.blocked.from_security_events');
                    }),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents::route('/'),
        ];
    }
}
