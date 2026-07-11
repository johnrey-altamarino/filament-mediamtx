<?php

namespace App\Filament\Pages;

use App\Models\BlockedIp;
use App\Models\MediamtxSession;
use App\Services\MediamtxApi;
use App\Services\MediamtxSessionRefresher;
use App\Support\UserAgentLabel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class Sessions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected string $view = 'filament.pages.sessions';

    protected static ?string $navigationLabel = 'Live Sessions';

    public function refreshSessions(): void
    {
        // Sync the snapshot from MediaMTX only while this page is open.
        // No background process runs; alerting is handled by the MediaMTX webhook.
        app(MediamtxSessionRefresher::class)->syncSnapshot();
    }

    public function table(Table $table): Table
    {
        return $table
            // ✅ THIS FIXES "must have a query()"
            ->query(MediamtxSession::query())
            ->columns([
                TextColumn::make('session_id')
                    ->label('Session ID')
                    ->formatStateUsing(fn (?string $state) => $state === null ? null : mb_substr($state, 0, 8))
                    ->tooltip(fn (?string $state) => $state)
                    ->searchable(),
                TextColumn::make('path')->searchable(),
                TextColumn::make('type')->badge()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('user_agent')
                    ->label('Client')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => UserAgentLabel::format($state))
                    ->tooltip(fn (?string $state) => $state)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('ip')->label('IP')->searchable(),
                TextColumn::make('created')->label('Created')->dateTime('M d, Y h:i:s A')->timezone('Asia/Manila')->sortable()->toggleable(),
                TextColumn::make('last_seen_at')->label('Last Seen')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('play')
                    ->label('Play')
                    ->icon('heroicon-o-play')
                    ->url(fn (MediamtxSession $record) => route('monitor.play', ['stream' => $record->path]))
                    ->openUrlInNewTab()
                    ->visible(fn (MediamtxSession $record) => $record->state === 'publish'),

                Action::make('kick')
                    ->label('Kick')
                    ->icon('heroicon-o-hand-raised')
                    ->requiresConfirmation()
                    ->action(function (MediamtxSession $record) {
                        app(MediamtxApi::class)->kick($record->type, $record->session_id);

                        activity('security')
                            ->causedBy(auth()->user())
                            ->withProperties([
                                'type' => $record->type,
                                'id' => $record->session_id,
                                'path' => $record->path,
                                'remote' => $record->remote_addr,
                            ])
                            ->log('session.kicked');

                        Notification::make()->title('Session kicked')->success()->send();
                    })
                    ->visible(fn (MediamtxSession $record) => $record->type !== 'hls'
                        && (auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false)),

                Action::make('block_ip')
                    ->label('Block IP')
                    ->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->action(function (MediamtxSession $record) {
                        if (! $record->ip) {
                            Notification::make()->title('No IP found')->danger()->send();

                            return;
                        }

                        BlockedIp::firstOrCreate(
                            ['ip' => $record->ip],
                            [
                                'reason' => 'Blocked from Sessions page',
                                'blocked_by_user_id' => auth()->id(),
                                'blocked_at' => now(),
                            ]
                        );

                        activity('security')
                            ->causedBy(auth()->user())
                            ->withProperties([
                                'ip' => $record->ip,
                                'type' => $record->type,
                                'id' => $record->session_id,
                                'path' => $record->path,
                            ])
                            ->log('ip.blocked.from_session');

                        Notification::make()->title("IP blocked: {$record->ip}")->success()->send();
                    })
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }
}
