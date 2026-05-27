<?php

namespace App\Filament\Widgets;

use App\Models\BlockedIp;
use App\Models\ViewerRequest;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class TopIpsTable extends BaseWidget
{
    protected static ?string $heading = 'Top IPs (Last 12 Hours)';

    protected static ?int $sort = 30;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'md';

    public function getTableRecordKey($record): string
    {
        return (string) ($record->ip ?? $record->id ?? '');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('requests')
                    ->label('Requests')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unique_streams')
                    ->label('Unique Streams')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unique_devices')
                    ->label('Unique Devices')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_blocked')
                    ->label('Blocked')
                    ->boolean()
                    ->sortable(),
            ])
            ->paginated(false)
            ->actions([
                Tables\Actions\Action::make('block')
                    ->label('Block')
                    ->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => (int) ($record->is_blocked ?? 0) === 0
                        && (auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false))
                    ->action(function ($record) {
                        BlockedIp::firstOrCreate(
                            ['ip' => $record->ip],
                            [
                                'reason' => 'Blocked from Top IPs widget',
                                'blocked_by_user_id' => auth()->id(),
                                'blocked_at' => now(),
                            ]
                        );

                        activity('security')
                            ->causedBy(auth()->user())
                            ->withProperties(['ip' => $record->ip])
                            ->log('ip.blocked.from_top_ips');

                        Notification::make()
                            ->title("Blocked IP: {$record->ip}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('copy_ip')
                    ->label('Copy')
                    ->icon('heroicon-o-clipboard')
                    ->action(fn () => null)
                    ->extraAttributes(fn ($record) => [
                        'x-on:click' => "navigator.clipboard.writeText('{$record->ip}')",
                    ]),
            ]);
    }

    private function getQuery(): Builder
    {
        $since = now()->subHours(12);

        return ViewerRequest::query()
            ->where('requested_at', '>=', $since)
            ->where('allowed', true)
            ->selectRaw('MIN(id) as id')
            ->selectRaw('ip')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COUNT(DISTINCT path) as unique_streams')
            ->selectRaw('COUNT(DISTINCT user_agent) as unique_devices')
            ->selectRaw('EXISTS(
                    SELECT 1 FROM blocked_ips b
                    WHERE b.ip = viewer_requests.ip
                ) as is_blocked')
            ->groupBy('ip')
            ->orderByRaw('COUNT(*) DESC');   // 👈 move sorting here
    }
}
