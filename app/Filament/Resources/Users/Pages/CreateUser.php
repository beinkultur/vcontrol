<?php

namespace App\Filament\Resources\Users\Pages;

use App\Access\AccountSafety;
use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        $violation = AccountSafety::violation(null, (array) ($this->data['roles'] ?? []), (bool) ($this->data['is_active'] ?? false));
        if ($violation !== null) {
            Notification::make()->danger()->title($violation)->send();
            $this->halt();
        }
    }
}
