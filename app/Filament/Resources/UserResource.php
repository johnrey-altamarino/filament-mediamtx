<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TagsColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\UserResource\Pages;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationGroup = 'System';
    protected static ?string $navigationLabel = 'Users';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationIcon = 'heroicon-o-users';

        public static function userFormSchema(bool $isCreate = false): array
    {
        return [
            TextInput::make('username')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('password')
                ->password()
                ->revealable()
                ->extraInputAttributes(['autocomplete' => $isCreate ? 'new-password' : 'new-password'])
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn () => $isCreate)
                ->minLength(8),

            TextInput::make('password_confirmation')
                ->password()
                ->revealable()
                ->dehydrated(false)
                ->extraInputAttributes(['autocomplete' => 'new-password'])
                ->required(fn ($get) => $isCreate || filled($get('password')))
                ->same('password')
                ->minLength(8),

            Select::make('roles')
                ->label('Roles')
                ->multiple()
                ->required()
                ->minItems(1)
                ->preload()
                ->searchable()
                ->options(fn () => Role::query()->pluck('name', 'name')->all())
                ->afterStateHydrated(function (Select $component, ?User $record) {
                    if (! $record) return;
                    $component->state($record->getRoleNames()->values()->all());
                }),

        ];
    }


            public static function form(Form $form): Form
        {
            return $form->schema(self::userFormSchema(isCreate: false));
        }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('username')->searchable()->sortable(),
                TagsColumn::make('roles.name')->label('Roles'),
                TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->form(fn (User $record) => self::userFormSchema(isCreate: false))
            ->fillForm(function (User $record): array {
                return [
                    'username' => $record->username,
                    'name' => $record->name,
                    'roles' => $record->getRoleNames()->values()->all(),
                    // password intentionally blank
                ];
            })
            ->action(function (User $record, array $data): void {
            $beforeRoles = $record->getRoleNames()->values()->all();

            $record->update([
                'username' => $data['username'],
                'name' => $data['name'],
                ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : []),
            ]);

            $incomingRoles = $data['roles'] ?? [];

            $wasSuperAdmin = $record->isSuperAdmin();
            $willBeSuperAdmin = in_array('super-admin', $incomingRoles, true);

            // If this user is currently super-admin and you are removing it...
            if ($wasSuperAdmin && ! $willBeSuperAdmin && User::superAdminCount() <= 1) {
                Notification::make()
                    ->title('Action blocked')
                    ->body('You cannot remove the last super-admin role.')
                    ->danger()
                    ->send();

                throw new Halt();
            }

            $record->syncRoles($incomingRoles);
            $afterRoles = $record->getRoleNames()->values()->all();

            activity('users')
                ->causedBy(auth()->user())
                ->performedOn($record)
                ->withProperties([
                    'username' => $record->username,
                    'before' => ['roles' => $beforeRoles],
                    'after' => ['roles' => $afterRoles],
                ])
                ->log($beforeRoles !== $afterRoles ? 'user.roles_updated' : 'user.updated');
        })
                ->modalHeading('Edit User')
                ->modalWidth('lg'),

                Tables\Actions\DeleteAction::make()
                ->before(function (User $record) {
                    if ($record->isSuperAdmin() && User::superAdminCount() <= 1) {
                        \Filament\Notifications\Notification::make()
                            ->title('Action blocked')
                            ->body('You cannot delete the last super-admin.')
                            ->danger()
                            ->send();

                        throw new \Filament\Support\Exceptions\Halt();
                    }
                })
                ->after(function (User $record) {
                    activity('users')
                        ->causedBy(auth()->user())
                        ->performedOn($record)
                        ->withProperties([
                            'username' => $record->username,
                            'roles' => $record->getRoleNames()->values()->all(),
                        ])
                        ->log('user.deleted');
                })
                        ])
            ->bulkActions([
            Tables\Actions\DeleteBulkAction::make()
            ->before(function (Tables\Actions\DeleteBulkAction $action) {
            // Capture usernames before deletion
            $records = $action->getRecords();

            activity('users')
                ->causedBy(auth()->user())
                ->withProperties([
                    'count' => $records->count(),
                    'usernames' => $records->pluck('username')->values()->all(),
                ])
                ->log('user.bulk_deleted');
                }),
        ]);
    }

    // --- Authorization gates for the resource ---
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'moderator']) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();

        if (! $user?->hasRole('super-admin')) {
            return false;
        }

        // prevent deleting yourself
        return (int) $record->getKey() !== (int) $user->getKey();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}

