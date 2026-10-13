<?php

namespace App\Filament\Resources\Users\Pages;

use App\Access\AccountSafety;
use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use BoxedPage;

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
        return UserForm::withLinks($data);
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        UserForm::savePositions($user, $this->data);
    }
}
