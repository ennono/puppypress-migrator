# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach [SemVer](https://semver.org/lang/de/).

## [1.1.0] – 2026-10-31

### Hinzugefügt
- **Medien beim Import herunterladen** (neuer Standard): Das Paket enthält nur noch die Liste der Mediendateien samt Größen. Der Import lädt sie parallel (8 Verbindungen) von der alten Website herunter, prüft HTTP-Status und Größe und lässt sich jederzeit fortsetzen. Vorab per FTP/rsync kopierte Dateien werden nur geprüft. Optional lässt sich eine andere Medien-Adresse angeben.
- Gründe, warum eine Mediendatei nicht übertragen wird: „Datei fehlt auf dem Server“ und „Nicht übertragen (Dateityp .svg ist aus Sicherheitsgründen gesperrt)“ getrennt, mit Zusammenfassung. Je Fall höchstens 50 Einzelzeilen.
- Filter `ppm_blocked_extensions`, z. B. um SVG zwischen vertrauenswürdigen Websites zu übertragen.
- WP-CLI: `--media-transfer=download|zip` beim Export, `--media-source=<url>` beim Import.

### Behoben
- Der Export großer Mediatheken brach ab. PHPs ZipArchive schreibt beim Hinzufügen das komplette Archiv neu, sodass jeder Schritt länger dauerte und doppelten Speicherplatz brauchte (gemeldet bei ca. 10.000 von 95.000 Dateien). Das ZIP enthält Medien jetzt nur noch auf ausdrücklichen Wunsch, mit Warnung ab 3.000 Dateien.
- Die Mediendatei-Etappe arbeitet nach Zeitbudget statt fester Stückzahl und macht garantiert Fortschritt.
- Ein erneuter Import lädt Dateien, die wegen eines Namenskonflikts in einen eigenen Ordner mussten, nicht noch einmal herunter.

### Geändert
- Paketformat Version 2 (Dateigrößen, Übertragungsart). Pakete der Version 1 lassen sich weiterhin importieren.

## [1.0.0] – 2026-10-31

### Hinzugefügt
- Export aller Vereinsdaten einer Website mit dem Theme „Extra Child“ (Inhaltstypen, Taxonomien inkl. leerer Begriffe, Meta-Felder, Beiträge, Seiten, Menüs, Kommentare, Widgets, Einstellungen, Medien mit allen Bildgrößen) in ein ZIP-Paket.
- Import in eine Website mit PuppyPress in fortsetzbaren Etappen. Alle ID-Verweise werden umgeschrieben (Zuchtzulassung, Körberichte, Abstammung, Vater-Deckrüde, Elternfotos, Züchter-Zwinger, Beitragsbilder, Hierarchien, Menüs, Galerien, Bild-Blöcke, Widgets).
- Schutz vor Duplikaten, keine überschriebenen Dateien, Prüfung der Paketinhalte (Zip-Slip, Dateitypen), URL-Ersetzung auch in serialisierten Daten.
- Admin-Oberfläche unter *Werkzeuge*, Eingangsordner für große Pakete, WP-CLI-Befehle, geschützter Speicher mit automatischer Bereinigung, deutsche Übersetzung.
- Roundtrip-Tests mit simuliertem WordPress.
