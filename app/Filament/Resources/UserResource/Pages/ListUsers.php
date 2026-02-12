<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createUser')
                ->label('New User')
                ->icon('heroicon-o-plus')
                ->modalHeading('Create User')
                ->modalWidth('lg')
                ->form(UserResource::userFormSchema(isCreate: true))
                ->visible(fn () => auth()->user()?->hasRole('super-admin'))
                ->action(function (array $data): void {
                /** @var User $user */
                $user = User::create([
                    'username' => $data['username'],
                    'name' => $data['name'],
                    'password' => $data['password'], // already hashed by dehydrateStateUsing
                ]);

                $user->syncRoles($data['roles'] ?? []);

                activity('users')
                    ->causedBy(auth()->user())
                    ->performedOn($user)
                    ->withProperties([
                        'username' => $user->username,
                        'roles' => $user->getRoleNames()->values()->all(),
                    ])
                    ->log('user.created');
            })
                ->successNotificationTitle('User created')
        ];
    }
}
