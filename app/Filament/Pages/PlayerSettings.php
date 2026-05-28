<?php

namespace App\Filament\Pages;

use App\Models\PlayerSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PlayerSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string $view = 'filament.pages.player-settings';

    protected static ?string $navigationLabel = 'Player Access';

    protected static ?string $navigationGroup = 'Control';

    protected static ?int $navigationSort = 4;

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'ip_mode'               => PlayerSetting::get('ip_mode', 'blacklist'),
            'allow_direct_playback' => (bool) PlayerSetting::get('allow_direct_playback', false),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('IP Access')
                    ->schema([
                        Select::make('ip_mode')
                            ->label('Player IP Access Mode')
                            ->options([
                                'blacklist' => 'Blacklist — allow all, block listed IPs',
                                'whitelist' => 'Whitelist — block all, allow listed IPs (use 0.0.0.0 to allow everyone)',
                            ])
                            ->required()
                            ->helperText('Changing mode takes effect within 30 seconds (cache TTL).'),
                    ]),
                Section::make('Direct Playback')
                    ->schema([
                        Toggle::make('allow_direct_playback')
                            ->label('Allow Direct Playback')
                            ->helperText('When enabled, visiting /play directly (from this app) can issue tokens without an AllowedReferrer entry. When disabled, the player page still loads but will not play unless this app\'s URL is added to Allowed Referrers.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PlayerSetting::updateOrCreate(
            ['key' => 'ip_mode'],
            ['value' => $data['ip_mode']]
        );

        PlayerSetting::updateOrCreate(
            ['key' => 'allow_direct_playback'],
            ['value' => $data['allow_direct_playback'] ? '1' : '0']
        );

        Notification::make()
            ->title('Player access settings saved')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }
}
