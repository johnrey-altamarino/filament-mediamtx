<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected array $beforeRoles = [];

    public function mount($record): void
    {
        parent::mount($record);

        // Capture roles at page load
        $this->beforeRoles = $this->record->getRoleNames()->values()->all();
    }

    protected function afterSave(): void
    {
        $afterRoles = $this->record->getRoleNames()->values()->all();
        $rolesChanged = $this->beforeRoles !== $afterRoles;

        activity('users')
            ->causedBy(auth()->user())
            ->performedOn($this->record)
            ->withProperties([
                'username' => $this->record->username,
                'before' => ['roles' => $this->beforeRoles],
                'after' => ['roles' => $afterRoles],
            ])
            ->log($rolesChanged ? 'User role updated' : 'User updated');

        // Update baseline so subsequent saves compare correctly
        $this->beforeRoles = $afterRoles;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function () {
                    $this->beforeRoles = $this->record->getRoleNames()->values()->all();
                })
                ->after(function () {
                    activity('users')
                        ->causedBy(auth()->user())
                        ->performedOn($this->record)
                        ->withProperties([
                            'username' => $this->record->username,
                            'roles' => $this->beforeRoles,
                        ])
                        ->log('Deleted user');
                }),
        ];
    }
}
