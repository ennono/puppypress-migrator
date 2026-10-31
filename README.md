# PuppyPress Migrator

**Zieht alle Vereinsdaten von der alten Website (Theme „Extra Child“) auf eine neue Website mit [PuppyPress](https://github.com/ennono/PuppyPress) um.**

Der Standard-Export von WordPress (WXR) reicht dafür nicht: Beim Import bekommen alle Einträge neue IDs, und viele Vereinsdaten verweisen per ID aufeinander. Der Migrator schreibt diese Verweise beim Import um.

| Verweis | Beispiel |
| --- | --- |
| Deckrüde → Zuchtzulassung, Körbericht | `license`, `approval` |
| Abstammung → Körberichte der 14 Ahnen | `father_approval` … `greatgrandmother_4_approval` |
| Wurf → Vater-Deckrüde, Fotos von Vater und Mutter | `father_stud_dog_id`, `father_photo`, `mother_photo` |
| Züchter → Zwinger | Term-Meta `breeder_kennel` |
| Beitragsbilder, Anhänge, Seitenhierarchie | `_thumbnail_id`, `post_parent` |
| Menüs | Menüpunkte, Eltern, Menüpositionen |
| Inhalte | `[gallery ids="…"]`, Bild- und Galerie-Blöcke, `wp-image-123` |
| Widgets | Menü-Widget, Bild- und Galerie-Widgets, URLs |

## Was übertragen wird

- **Vereinsdaten (immer):** alle 11 Inhaltstypen, alle 19 Taxonomien (auch Begriffe ohne Einträge) mit Hierarchie und Feldern, alle Meta-Felder
- **Optional:** Beiträge und Seiten (inkl. Seitentemplates), Menüs, Kommentare (ohne Spam und Papierkorb), Widgets
- **Medien:** die gesamte Mediathek, nur verwendete Medien oder keine. Übertragen werden Originaldateien und alle erzeugten Bildgrößen; Bilder müssen also nicht neu berechnet werden. Wie die Dateien ankommen, steht unter [Mediendateien](#mediendateien).
- **Einstellungen:** Startseite, Beitragsseite, oben gehaltene Beiträge, Menüpositionen
- **Autoren:** werden über Login oder E-Mail zugeordnet; fehlende Autoren werden optional angelegt.

Nicht übertragen werden Revisionen, automatische Entwürfe, Plugin- und Theme-Einstellungen sowie Benutzerkonten, die keine Inhalte haben.

## Ablauf

1. **Alte Website:** Plugin installieren und aktivieren, dann *Werkzeuge → PuppyPress-Migration → Export erstellen*. Das ZIP wird heruntergeladen.
2. **Neue Website:** PuppyPress **und** den Migrator installieren und aktivieren. Unter *Werkzeuge → PuppyPress-Migration* das ZIP hochladen und *Import starten*.
3. Danach *Einstellungen → Permalinks* einmal speichern und die Prüfliste in der [Migrationsanleitung von PuppyPress](https://github.com/ennono/PuppyPress/blob/main/docs/MIGRATION.md) durchgehen.
4. Den Migrator auf beiden Websites deaktivieren und löschen.

Große Pakete, die das Upload-Limit überschreiten, per FTP/SFTP nach `wp-content/uploads/puppypress-migrator/incoming/` hochladen; sie erscheinen dann im Importformular.

### Zwischenstand und Fortsetzen

Ein Vorgang läuft nur, solange die Migrationsseite geöffnet ist. Unten auf der Seite listet die Übersicht **Vorgänge** alle Exporte und Importe mit:
- Status (läuft, pausiert, abgeschlossen, fehlgeschlagen) und aktueller Etappe
- Zahlen, z. B. „Mediendateien: 10.350 von 94.710 geprüft (9.800 heruntergeladen, 540 bereits vorhanden, 10 fehlgeschlagen)“
- vollständigem Protokoll

Pausierte Vorgänge lassen sich dort mit **Fortsetzen** weiterführen, auch nach dem Neuladen oder in einem anderen Browser.

Wo die Mediendateien landen:
- direkt an ihrem endgültigen Platz in `wp-content/uploads/…`, mit demselben Pfad wie auf der alten Website. Per FTP sind sie sofort sichtbar.
- in der **Mediathek** erst nach der Etappe „Beiträge werden angelegt“.
- während des Ladens kurz mit der Endung `.ppm-part`
- bei einem Namenskonflikt in `wp-content/uploads/ppm-import-…/`

### Per WP-CLI (empfohlen für große Websites)

```bash
wp puppypress-migrator export --dir=/tmp                      # Medien werden beim Import geladen
wp puppypress-migrator export --dir=/tmp --media-transfer=zip # Medien ins ZIP packen
wp puppypress-migrator import /tmp/puppypress-export-alte-domain.de-2026-10-31.zip --author=admin
wp puppypress-migrator status                                 # Vorgänge und Zwischenstand
wp puppypress-migrator resume <id>                            # pausierten Vorgang fortsetzen
```

Weitere Optionen: `wp help puppypress-migrator export` und `wp help puppypress-migrator import`.

## Mediendateien

Für die Medien gibt es zwei Übertragungswege:

| Weg | Wie | Geeignet für |
| --- | --- | --- |
| **Beim Import herunterladen** (Standard) | Das Paket enthält nur die Dateiliste mit Größen. Der Import lädt die Dateien parallel von der alten Website herunter und prüft Status und Größe. | Jede Größe, auch Mediatheken mit 100.000 Dateien. Die alte Website muss bis zum Ende des Imports erreichbar sein. |
| **In die ZIP-Datei packen** | Alles steckt in einem Paket. | Kleine Mediatheken (wenige tausend Dateien) oder eine alte Website, die die neue nicht erreichen kann |

**Noch schneller bei sehr großen Mediatheken:** den Ordner `wp-content/uploads` vorher per rsync oder FTP auf die neue Website kopieren. Der Import erkennt vorhandene Dateien mit passender Größe und lädt nur, was fehlt oder unvollständig ist:

```bash
rsync -av alter-server:/pfad/zu/wp-content/uploads/ /pfad/zu/neuer/wp-content/uploads/
```

Ist die alte Website seit dem Export umgezogen, lässt sich beim Import eine andere Medien-Adresse angeben (Feld „Medien herunterladen von“ bzw. `--media-source`). Schlagen Downloads fehl, etwa weil die alte Website kurz nicht erreichbar war, den Import einfach noch einmal starten: Er lädt nur die fehlenden Dateien nach.

### Meldungen beim Export

- **„Datei fehlt auf dem Server“:** Die Mediathek kennt die Datei, auf dem Server liegt sie aber nicht (gelöscht, verschoben, bei einem Auslagerungs-Plugin wie S3 oder mit absolutem Pfad gespeichert).
- **„Nicht übertragen (Grund)“:** Die Datei existiert, wird aber nicht übertragen:
  - aus Sicherheitsgründen gesperrte Typen (`.svg`, `.html`, `.js`, `.php`, `.exe` …)
  - doppelte Endungen wie `bild.php.jpg`
  - Typen, die WordPress nicht kennt
  - auffällige Pfade

SVG zwischen zwei vertrauenswürdigen eigenen Websites erlauben (auf beiden Seiten, die neue Website braucht zusätzlich SVG-Unterstützung, z. B. „Safe SVG“):

```php
add_filter( 'ppm_blocked_extensions', function ( $extensions ) {
	return array_diff( $extensions, array( 'svg' ) );
} );
```

## Sicherheit und Robustheit

- **Läuft in Etappen:** Export und Import arbeiten in kurzen Schritten (ca. 15 s pro Anfrage). Es gibt keine Timeouts, und ein abgebrochener Vorgang läuft per „Erneut versuchen“ weiter.
- **Keine Duplikate:** Jeder importierte Eintrag merkt sich seine Herkunft (`_ppm_source`). Wird dasselbe Paket erneut importiert, überspringt der Migrator vorhandene Einträge. Vorhandene Begriffe mit gleichem Slug werden wiederverwendet.
- **Lücken nachholen:** Ein erneuter Import ergänzt, was beim ersten Lauf nicht ging: fehlende Mediendateien und Taxonomie-Zuordnungen. Das betrifft z. B. die Beitragsformate von Extra/Divi (`et_post_format`), wenn das Theme beim ersten Lauf noch nicht aktiv war. Am besten das spätere Theme (Extra) also schon **vor** dem Import aktivieren.
- **Keine überschriebenen Dateien:** Liegt auf der neuen Website schon eine andere Datei unter demselben Pfad, landet die importierte Datei samt allen Bildgrößen in `uploads/ppm-import-…/`. Links und Metadaten werden entsprechend angepasst.
- **Geprüfte Pakete:** Entpackt werden nur Medien mit erlaubten Dateitypen und sauberen Pfaden. Pfade wie `../`, PHP-Dateien oder doppelte Endungen werden abgewiesen (Schutz vor Zip-Slip). Serialisierte Daten werden ohne Objekte entpackt.
- **Datenschutz:** Exporte enthalten personenbezogene Daten. Sie liegen in einem gegen Webzugriff geschützten Ordner mit zufälligem Namen, lassen sich nur von Administratoren herunterladen und werden drei Tage nach der letzten Aktivität automatisch gelöscht.
- Die Inhalte werden unverändert übernommen. Damit nichts verloren geht, ist der HTML-Filter (kses) während des Imports abgeschaltet.

## Anpassen

Die Liste der Inhaltstypen, Taxonomien und ID-Felder ist über Filter erweiterbar, z. B. für eigene Felder:

```php
add_filter( 'ppm_post_id_meta_keys', function ( $keys ) {
	$keys[] = 'mein_verweis_auf_einen_beitrag';
	return $keys;
} );
```

Verfügbare Filter: `ppm_club_post_types`, `ppm_club_taxonomies`, `ppm_post_statuses`, `ppm_post_id_meta_keys`, `ppm_post_id_list_meta_keys`, `ppm_term_id_term_meta_keys`, `ppm_excluded_post_meta_keys`, `ppm_blocked_extensions`, `ppm_download_batch`, `ppm_step_seconds`.

## Entwicklung

```bash
php tests/run.php            # Roundtrip-Tests mit simuliertem WordPress
php bin/i18n.php pot         # Übersetzungen extrahieren
php bin/i18n.php update      # POT in die PO-Dateien übernehmen
php bin/i18n.php compile     # PO → MO und .l10n.php
bin/build.sh                 # installierbares ZIP nach dist/
composer install && composer lint   # optional: WordPress Coding Standards
```

Die Tests simulieren zwei Websites im Speicher: Sie exportieren eine „alte“ Website mit Deckrüden, Abstammung, Würfen, Zwingern, Menüs, Kommentaren und Widgets und importieren sie in eine „neue“. Danach prüfen sie, ob alle Verweise stimmen, ob sich ein Import wiederholen und fortsetzen lässt und ob manipulierte Pakete abgewiesen werden.

## Lizenz

GPL-2.0-or-later © Timo Fricke
