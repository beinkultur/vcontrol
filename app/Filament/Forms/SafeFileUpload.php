<?php

namespace App\Filament\Forms;

use App\Support\Uploads;
use Closure;
use Filament\Forms\Components\FileUpload;

/**
 * Datei-Upload, der nur erlaubte Endungen annimmt: Der Auswahldialog des
 * Browsers zeigt nur diese Dateien, FilePond lädt andere gar nicht erst hoch
 * (auch nicht per Ziehen), und der Server prüft Endung, Inhalt und Größe –
 * beim Hochladen in die Zwischenablage (App\Support\Uploads) und noch einmal
 * beim Speichern.
 *
 * Bewusst nicht acceptedFileTypes() mit MIME-Typen: Filament prüft damit beim
 * Speichern den erkannten Inhalt (mimetypes), und den erkennt der Server bei
 * echten DWG-, CSV- oder Office-Dateien je nach Version anders, als der Browser
 * ihn meldet. Stattdessen bildet mimeTypeMap jede erlaubte Endung auf sich
 * selbst ab („pdf“ → „.pdf“): FilePond entscheidet dann nach der Endung, und
 * seine Fehlermeldung nennt „.pdf“ statt „application/pdf“.
 */
class SafeFileUpload extends FileUpload
{
    /** @param  list<string>|Closure(): list<string>  $extensions  ohne Punkt */
    public function allowedExtensions(array | Closure $extensions): static
    {
        $list = fn (): array => array_map('strtolower', (array) $this->evaluate($extensions));

        $this->acceptedFileTypes = fn (): array => array_map(fn (string $extension): string => '.' . $extension, $list());
        $this->mimeTypeMap(fn (): array => collect($list())->mapWithKeys(fn (string $extension): array => [$extension => '.' . $extension])->all());
        $this->maxSize(Uploads::MAX_KB);
        $this->rule(fn (): string => 'extensions:' . implode(',', $list()));
        // Die Endung allein sagt nichts: HTML als „.pdf“ wäre ausführbarer Inhalt, ein Programm als „.pdf“ ebenso
        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (Uploads::hasBlockedContent($value)) {
                $fail(Uploads::BLOCKED_MESSAGE);
            }
        });

        return $this;
    }
}
