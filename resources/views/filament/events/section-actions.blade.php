{{--
    Speichern/Abbrechen am Ende der Reiterzeile der Unterbereiche (Render-Hook
    EventForm::SECTION_ACTIONS_HOOK, nur auf EditEvent). Wie Filaments Knöpfe unter
    dem Formular, die rechtsbündig ebenfalls „Abbrechen, Speichern“ zeigen:
    Speichern schickt das Formular „form“ ab, Abbrechen geht zurück.
    Stil: .vc-sections__actions in public/css/vcontrol.css (rechtsbündig, bei
    Platzmangel in der nächsten Zeile).
--}}
<div class="vc-sections__actions">
    <x-filament::button
        color="gray"
        size="sm"
        x-on:click="document.referrer ? window.history.back() : Livewire.navigate({{ \Illuminate\Support\Js::from($cancelUrl) }})"
    >
        {{ __('filament-panels::resources/pages/edit-record.form.actions.cancel.label') }}
    </x-filament::button>
    <x-filament::button type="submit" form="form" size="sm">
        {{ __('filament-panels::resources/pages/edit-record.form.actions.save.label') }}
    </x-filament::button>
</div>
