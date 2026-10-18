<?php

namespace App\Filament\Resources\Users\Pages;

use App\Access\AccountSafety;
use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Users\Pages\Concerns\SendsAccessData;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    use BoxedPage;
    use SendsAccessData;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $violation = AccountSafety::violation($user, (array) ($this->data['roles'] ?? []), (bool) ($this->data['is_active'] ?? false), (array) ($this->data['calendar_permissions'] ?? []));
        if ($violation !== null) {
            Notification::make()->danger()->title($violation)->send();
            $this->halt();
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->rememberPassword($data);
        // Ein neues Passwort für jemand anderen: beim nächsten Anmelden ein eigenes festlegen.
        // Das eigene Konto nicht – das Passwort hat man dann selbst gewählt.
        if (filled($data['password'] ?? null) && !$this->getRecord()->is(Auth::user())) {
            $data['must_change_password'] = true;
        }

        return UserForm::withLinks($data);
    }

    protected function afterSave(): void
    {
        $this->sendAccessDataIfRequested(isNew: false);
    }
}
