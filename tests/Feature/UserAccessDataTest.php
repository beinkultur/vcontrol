<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Mail\AccessDataMail;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\AccessData;
use App\Support\AuditPresenter;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Verwaltung › Benutzer: Passwort generieren und Zugangsdaten per E-Mail senden. */
class UserAccessDataTest extends TestCase
{
    private const PATTERN = '/^[A-HJ-NP-Za-km-z2-9]{4}(-[A-HJ-NP-Za-km-z2-9]{4}){3}$/';

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Setting::put(Setting::VENUE_NAME, 'Be-In Arena');
        $this->roleId = $this->role('catering', ['kalender' => 'read'])->id;
        $this->actingAs($this->admin());
    }

    /** @return array<string, mixed> */
    private function newUser(array $overrides = []): array
    {
        return [
            'first_name' => 'Lena',
            'last_name' => 'Hoffmann',
            'email' => 'lena.hoffmann@example.org',
            'roles' => [$this->roleId],
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_generiertes_passwort(): void
    {
        $passwords = array_map(fn (): string => AccessData::password(), range(1, 50));
        foreach ($passwords as $password) {
            $this->assertMatchesRegularExpression(self::PATTERN, $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/\d/', $password);
        }
        $this->assertCount(50, array_unique($passwords));
    }

    public function test_generieren_fuellt_das_feld_und_schaltet_den_versand_ein(): void
    {
        $page = Livewire::test(CreateUser::class)
            ->assertFormFieldIsVisible('send_access_data')
            ->assertSchemaStateSet(['send_access_data' => null])
            ->callAction(TestAction::make('generatePassword')->schemaComponent('password', 'form'));

        $this->assertMatchesRegularExpression(self::PATTERN, (string) $page->get('data.password'));
        $this->assertTrue($page->get('data.send_access_data'));
    }

    public function test_anlegen_mit_zugangsdaten(): void
    {
        Mail::fake();
        $password = AccessData::password();

        Livewire::test(CreateUser::class)
            ->fillForm($this->newUser(['password' => $password, 'send_access_data' => true]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Zugangsdaten an lena.hoffmann@example.org verschickt.');

        $user = User::query()->where('email', 'lena.hoffmann@example.org')->sole();
        $this->assertTrue(Hash::check($password, $user->password));
        Mail::assertSent(AccessDataMail::class, fn (AccessDataMail $mail): bool => $mail->hasTo('lena.hoffmann@example.org')
            && $mail->password === $password && $mail->isNew && $mail->accountName === 'Lena Hoffmann');
        Mail::assertSentCount(1);

        // Im Protokoll: an wen, aber nie das Passwort
        $log = AuditLog::query()->where('subject', 'users')->where('new_values', 'like', '%credentials_sent_to%')->sole();
        $this->assertSame((string) $user->id, $log->subject_key);
        $this->assertSame('Zugangsdaten verschickt an', AuditPresenter::field('users', 'credentials_sent_to'));
        $this->assertStringNotContainsString($password, AuditLog::query()->get()->toJson());
    }

    public function test_ohne_haken_oder_inaktiv_keine_mail(): void
    {
        Mail::fake();

        Livewire::test(CreateUser::class)
            ->fillForm($this->newUser(['password' => 'Geheim-2026-Test']))
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreateUser::class)
            ->fillForm($this->newUser(['email' => 'inaktiv@example.org', 'is_active' => false, 'password' => 'Geheim-2026-Test', 'send_access_data' => true]))
            ->assertFormFieldIsHidden('send_access_data')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, User::query()->whereIn('email', ['lena.hoffmann@example.org', 'inaktiv@example.org'])->count());
        Mail::assertNothingSent();
    }

    public function test_bearbeiten_nur_mit_neuem_passwort(): void
    {
        Mail::fake();
        $user = $this->userWith(Role::query()->findOrFail($this->roleId));
        $old = $user->password;
        $page = fn () => Livewire::test(EditUser::class, ['record' => $user->getRouteKey()]);

        // Ohne neues Passwort gibt es nichts zu senden
        $page()->assertFormFieldIsHidden('send_access_data')
            ->fillForm(['phone' => '040 123', 'send_access_data' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($old, $user->fresh()->password);
        Mail::assertNothingSent();

        $edit = $page()
            ->callAction(TestAction::make('generatePassword')->schemaComponent('password', 'form'))
            ->assertFormFieldIsVisible('send_access_data');
        $password = (string) $edit->get('data.password');
        $edit->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Zugangsdaten an ' . $user->email . ' verschickt.')
            // Danach ist das Feld leer – nochmal Speichern schickt nichts zweimal
            ->assertSchemaStateSet(['password' => null, 'send_access_data' => false])
            ->call('save');

        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        Mail::assertSent(AccessDataMail::class, fn (AccessDataMail $mail): bool => $mail->hasTo($user->email) && !$mail->isNew && $mail->password === $password);
        Mail::assertSentCount(1);
    }

    public function test_mail_scheitert_konto_bleibt(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP nicht erreichbar'));

        Livewire::test(CreateUser::class)
            ->fillForm($this->newUser(['password' => 'Geheim-2026-Test', 'send_access_data' => true]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Die Mail an lena.hoffmann@example.org ging nicht raus.');

        $this->assertTrue(User::query()->where('email', 'lena.hoffmann@example.org')->exists());
        $this->assertFalse(AuditLog::query()->where('new_values', 'like', '%credentials_sent_to%')->exists());
    }

    public function test_inhalt_der_mail(): void
    {
        Setting::put(Setting::MAIL_REPLY_TO, 'buero@be-in-arena.example');
        // Adresse der jeweiligen Installation (APP_URL) – in Produktion nicht localhost
        $login = Filament::getPanel('app')->getLoginUrl();
        // Sonderzeichen bleiben, wie sie sind – kein Markdown, kein HTML
        $mail = new AccessDataMail('Lena Hoffmann', 'lena.hoffmann@example.org', 'a*b*c_d_e<f>&g', true);

        $mail->assertHasSubject('Ihr Zugang zu VenueControl – Be-In Arena');
        $mail->assertHasReplyTo('buero@be-in-arena.example');
        $mail->assertSeeInHtml('Guten Tag Lena Hoffmann,');
        $mail->assertSeeInHtml('für Sie gibt es jetzt einen Zugang zu VenueControl bei Be-In Arena.');
        $this->assertStringEndsWith('/login', $login);
        $mail->assertSeeInHtml($login);
        $mail->assertSeeInHtml('lena.hoffmann@example.org');
        $mail->assertSeeInHtml('a*b*c_d_e&lt;f&gt;&amp;g', false);
        $mail->assertDontSeeInHtml('<em>', false);
        $mail->assertSeeInText('Passwort:       a*b*c_d_e<f>&g');
        $mail->assertSeeInText('Anmeldung:      ' . $login);

        $renewed = new AccessDataMail('Lena Hoffmann', 'lena.hoffmann@example.org', 'Xk7m-Pq2r-Tz9w-Hb4n', false, 'admin@example.org', 'Admin');
        $renewed->assertHasSubject('Ihr neues Passwort für VenueControl – Be-In Arena');
        $renewed->assertSeeInHtml('Ihr Passwort für VenueControl bei Be-In Arena wurde neu vergeben.');
        // Ohne Antwortadresse der Halle an den Admin, der verschickt
        Setting::put(Setting::MAIL_REPLY_TO, null);
        $renewed->assertHasReplyTo('admin@example.org');
    }
}
