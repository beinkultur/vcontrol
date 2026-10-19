/*
 * Eigene Skripte des Panels ohne Build-Schritt, eingebunden im Kopf der Seite
 * (AppPanelProvider, Render-Hook HEAD_END). Bleibt beim Seitenwechsel im
 * SPA-Modus geladen – deshalb nur einmal einrichten.
 */
(() => {
    if (window.vcPanelScripts) {
        return;
    }
    window.vcPanelScripts = true;

    /*
     * Menügruppen der Kopfzeile (Finanzen, Stammdaten, Verwaltung …) und das
     * Benutzermenü klappen in Filament schon beim Drücken der Maustaste auf
     * und beim nächsten Drücken wieder zu. Ein Doppelklick oder eine prellende
     * Maustaste drückt zweimal: Die Gruppe ging auf und sofort wieder zu, der
     * Klick schien nicht zu wirken – Menüpunkte ohne Unterpunkte sind Links
     * und merken davon nichts (gemeldet aus Firefox unter Windows,
     * 19.10.2026). Den zweiten Druck desselben Doppelklicks (detail > 1)
     * bekommt Filament deshalb nicht mehr zu sehen; zu geht die Gruppe weiter
     * mit einem einzelnen Klick, einem Klick daneben oder Escape.
     */
    document.addEventListener('mousedown', (event) => {
        if (event.button !== 0 || event.detail < 2 || !(event.target instanceof Element)) {
            return;
        }
        if (event.target.closest('.fi-topbar .fi-dropdown-trigger')) {
            event.stopPropagation();
        }
    }, true);
})();
