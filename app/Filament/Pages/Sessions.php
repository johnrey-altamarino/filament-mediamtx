<?php

namespace App\Filament\Pages;

use App\Models\BlockedIp;
use App\Models\MediamtxSession;
use App\Services\MediamtxApi;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

class Sessions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static string $view = 'filament.pages.sessions';

    protected static ?string $navigationLabel = 'Live Sessions';


    public function refreshSessions(): void
    {
        // TODO (Task 9): call syncSnapshot() to refresh sessions on-demand.
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            // ✅ THIS FIXES "must have a query()"
            ->query(MediamtxSession::query())
            ->columns([
                Tables\Columns\TextColumn::make('created')->label('Created')->dateTime('M d, Y h:i:s A')->timezone('Asia/Manila')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('type')->badge()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('state')->badge()->sortable(),
                Tables\Columns\TextColumn::make('session_id')->label('Session ID')->searchable(),
                Tables\Columns\TextColumn::make('path')->searchable(),
                Tables\Columns\TextColumn::make('ip')->label('IP')->searchable(),
                Tables\Columns\TextColumn::make('bytes_received')->label('Bytes In')->numeric()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('bytes_sent')->label('Bytes Out')->numeric()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last Seen')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('kick')
                    ->label('Kick')
                    ->icon('heroicon-o-hand-raised')
                    ->requiresConfirmation()
                    ->action(function (MediamtxSession $record) {
                        app(MediamtxApi::class)->kickWebRtc($record->session_id);

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
                    ->visible(fn () => auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false),

                Tables\Actions\Action::make('block_ip')
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
