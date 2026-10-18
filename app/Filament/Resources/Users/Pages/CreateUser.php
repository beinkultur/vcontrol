<?php

namespace App\Filament\Resources\Users\Pages;

use App\Access\AccountSafety;
use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Users\Pages\Concerns\SendsAccessData;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use BoxedPage;
    use SendsAccessData;

    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        $violation = AccountSafety::violation(null, (array) ($this->data['roles'] ?? []), (bool) ($this->data['is_active'] ?? false), (array) ($this->data['calendar_permissions'] ?? []));
        if ($violation !== null) {
            Notification::make()->danger()->title($violation)->send();
            $this->halt();
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->rememberPassword($data);
        // Das Passwort kennt der Admin: beim ersten Anmelden ein eigenes festlegen
        $data['must_change_password'] = true;

        return UserForm::withLinks($data);
    }

    protected function afterCreate(): void
    {
        $this->sendAccessDataIfRequested(isNew: true);
    }
}
