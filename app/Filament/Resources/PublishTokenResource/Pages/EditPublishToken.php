<?php

namespace App\Filament\Resources\PublishTokenResource\Pages;

use App\Filament\Resources\PublishTokenResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPublishToken extends EditRecord
{
    protected static string $resource = PublishTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
