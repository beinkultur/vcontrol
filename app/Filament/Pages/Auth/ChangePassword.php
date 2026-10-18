<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * „Neues Passwort festlegen“ (/passwort-aendern, seit 18.10.2026): Pflicht nach
 * der Anmeldung, solange das Passwort von einem Admin stammt
 * (users.must_change_password, RequirePasswordChange leitet hierher). Neues
 * Passwort zweimal, nicht das bisherige; danach weiter zur ursprünglich
 * angefragten Seite. Wie im Profil bleibt diese Sitzung angemeldet, andere
 * Geräte meldet AuthenticateSession ab.
 *
 * @property-read Schema $form
 */
class ChangePassword extends SimplePage
{
    public const ROUTE = 'filament.app.auth.password-change';

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    public function mount(): void
    {
        if (!$this->user()->must_change_password) {
            redirect()->intended(Filament::getUrl());

            return;
        }
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Neues Passwort festlegen';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Neues Passwort festlegen';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Ihr bisheriges Passwort hat die Verwaltung vergeben. Bitte legen Sie jetzt ein eigenes fest – danach geht es weiter.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('password')
                ->label('Neues Passwort')
                ->helperText('Mindestens 8 Zeichen, nicht das bisherige.')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required()
                ->rule(Password::default())
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                    if (is_string($value) && Hash::check($value, (string) $this->user()->getAuthPassword())) {
                        $fail('Bitte ein anderes Passwort als das bisherige wählen.');
                    }
                })
                ->same('passwordConfirmation')
                ->validationAttribute('Neues Passwort'),
            TextInput::make('passwordConfirmation')
                ->label('Neues Passwort wiederholen')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required()
                ->dehydrated(false),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = $this->user();
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        // Diese Sitzung bleibt angemeldet (AuthenticateSession vergleicht den Hash)
        session()->put(['password_hash_' . Filament::getAuthGuard() => $user->getAuthPassword()]);

        Notification::make()->success()->title('Neues Passwort gespeichert.')->send();
        // Weiter zur Seite, die vor der Umleitung angefragt war (RequirePasswordChange: redirect()->guest())
        $this->redirect((string) session()->pull('url.intended', Filament::getUrl()));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            Actions::make([
                Action::make('logout')
                    ->label('Abmelden')
                    ->link()
                    ->color('gray')
                    ->action(function (): void {
                        Filament::auth()->logout();
                        session()->invalidate();
                        session()->regenerateToken();
                        $this->redirect(Filament::getLoginUrl());
                    }),
            ])->alignment(Alignment::Center),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Passwort speichern')
                        ->submit('save'),
                ])->fullWidth(),
            ]);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
