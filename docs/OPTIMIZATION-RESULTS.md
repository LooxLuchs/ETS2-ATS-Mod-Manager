# Optimierungsergebnisse

Stand: 2026-10-14. Umsetzung der sieben Punkte aus der Analyse von Version 0.5.2. Bedienabläufe, Rechteprüfungen, DAT-Inhalte, Backups, manuelle Neustarts und Recovery bleiben erhalten. Die neue Annahmegrenze beträgt 120 Mods; der Hinweis erscheint einschließlich 100 Mods.

## Umsetzung in der Reihenfolge der Analyse

| Punkt | Umsetzung | Nachweis |
| --- | --- | --- |
| 1. DAT und Speicher | Uploads und ZIP-Einträge werden in temporäre Dateien gelesen. Wings-Lesezugriffe sind begrenzt und gestreamt; Hashprüfungen benötigen keine DAT-Strings. Die Übersicht hasht DAT ohne temporäre Kopie. ZIP-Downloads lesen DAT vom Dateipfad. Edits und Restores verwenden den bereits geprüften Ausgangssnapshot weiter. | Binär-, Größen-, Truncation-, Upload-, Rollback- und Speichertests. |
| 2. Pfadprüfungen | Paare und Rename-Quell-/Zielpfade teilen Vorfahrenabfragen innerhalb einer Inspektion. Vorbereitung und abschließende Prüfung werden paarweise geprüft. Die nächste Operation inspiziert Pfade erneut. | IO-Messungen sowie Tests gegen Symlinks, beschädigte Staging-Dateien und externe Änderungen. |
| 3. Datenbank | Listen laden Zusammenfassungen statt aller Mod-Arrays. Die Herkunftssuche filtert Hashes in SQL; Backuplisten projizieren die benötigte Revision. Eine neue Migration ergänzt zusammengesetzte Indizes. | HTTP-Tests prüfen vollständige Details, kompakte Listen, Rechte und strikt verglichene JSON-Hashes. |
| 4. Parser | Kompakte Token-Spalten, ID-Mengen, einmaliger Textaufbau und zwischengespeicherte Mod-Metadaten. Controller, Manager und Transaktion teilen eine requestbezogene PackageUpload-Instanz mit höchstens zwei SII-Dokumenten. | Vergleich mit dem ursprünglichen Parser sowie Grenz-, Reihenfolge-, BOM-, CRLF- und Span-Erhaltungstests. |
| 5. Aktualisierung | Preset-Änderungen laden Bibliothek und Details ohne erneuten Zugriff auf die installierte DAT. Dateiänderungen, manuelles Refresh und Fehler prüfen weiter den aktiven Zustand vollständig. Überholte Bibliotheksantworten überschreiben keinen neueren aktiven Snapshot. | UI-Tests für Umbenennen ohne Overview-Aufruf, Fehler-Fallback, sequentielle Aktualisierung und konkurrierende Snapshots. |
| 6. Frontend | Memoisierte Mod-Zeilen, stabile Sortier-Callbacks, lineare Entfernungserkennung, Positionsmessungen nur für betroffene Zeilen und gebündelte Animationen. Stationäre Pointer erzeugen keine wiederholten Hit-Tests außerhalb des notwendigen Autoscrolls. | Pointer-/Abbruchtests, 120-Mod-UI-Tests und Chromium-Prüfung mit Maus und Touch. |
| 7. Wartbarkeit | Gemeinsame Berechtigungen, Dateiauswahlregeln, Query-Keys, Presence-Einstellungen und Timeouts. Lokale SDK-Vorbereitung und CI lesen den Panel-Pin aus compatibility.json. Mod-Zeilen und Grenzhinweise sind eigene Komponenten. | Typprüfung, Builds, PHP-Syntax, Doctor und Paketprüfung. |

## Mod-Grenzen

- 0 bis 99 Mods: akzeptiert, ohne Grenzhinweis.
- 100 bis 120 Mods: akzeptiert, mit Hinweis in Mod Manager und Console Quick Switcher.
- Ab 121 Mods: Pakete mit zu vielen Referenzen werden abgewiesen. Order-Anfragen mit mehr als 120 IDs scheitern bereits an der HTTP-Validierung.
- Die Annahmegrenze der Extension erhöht nicht die vom Benutzer vorgegebene Convoy-Grenze von 100 Mods. Bestehende übergroße Presets werden nicht gelöscht; vor Aktivierung müssen sie durch ein unterstütztes Paket ersetzt werden.

## Messungen

IO-Messung mit Wings-Testdoubles, dem üblichen ETS2-Zielpfad, der 70-Mod-Fixture und 1 MiB DAT. Die Zahlen sind Aufrufzahlen, keine Produktionslaufzeiten.

| Vorgang | Verzeichnisabfragen vorher | Danach | Datei-Lesezugriffe danach |
| --- | ---: | ---: | ---: |
| Vorhandenes Paar ersetzen | 104 | 76 | 10 |
| Leeres Ziel installieren | 72 | 54 | 4 |
| Aktive Dateien für Übersicht prüfen | 16 | 8 | 2 |

Bei einer normalen Ersetzung bleiben zwei Schreibzugriffe mit insgesamt 1.066.511 Payload-Bytes erhalten. Originaldateien werden weiterhin ohne Backup-Upload verschoben. Integritätsprüfungen wurden nicht entfernt. Edits und Restores sparen zusätzlich das erneute Lesen ihres verifizierten Ausgangspaars; reine Bibliotheksaktualisierungen übertragen keine installierte DAT.

Der lokale Speichertest `php -d memory_limit=32M tests/stream-memory.php` erzeugt und validiert eine 256-MiB-DAT. Der gemessene PHP-Allokationshöchststand beträgt 2 MiB. Hash und Größe stimmen; die temporäre Datei wird nach Freigabe entfernt. Dieser Test misst den Paketpfad ohne den zusätzlichen Speicherbedarf des vollständigen Panels und benötigt temporären Festplattenspeicher.

Ein Vergleich mit dem ursprünglichen Parser verwendete minimal erzeugte SII-Dokumente und 25 Durchläufe je Variante. Gemessen wurde der Edit-Ablauf einschließlich Ausgangsvalidierung, Bearbeitung, Ergebnisvalidierung und Transaktionsvalidierung, ohne Netzwerk oder Datenbank:

| Mods | Alter Ablauf, Median | Neuer Ablauf, Median |
| --- | ---: | ---: |
| 70 | 9,93 ms | 3,41 ms |
| 120 | 18,00 ms | 6,51 ms |

Metadaten und bearbeitete Dateibytes wurden zusätzlich gegen den ursprünglichen Parser verglichen: 70 und 120 Mods, LF/CRLF/BOM, Umordnen, Entfernen und leere Mod-Liste ergeben dieselben Ergebnisse. Einzelne Parser-Aufrufe sind nicht der Hauptgewinn; vermiedene Wiederholungen im vollständigen Ablauf sind entscheidend.

Die 70-Mod-Fixture enthält 18.456 Bytes vollständiges Metadaten-JSON. Die für die Bibliothek benötigten Metadaten umfassen 109 Bytes; weitere Preset-Felder kommen jeweils hinzu. Das ist kein Vergleich der gesamten HTTP-Antwort.

## Grenzen und erhaltene Schutzmechanismen

Die [geprüfte Wings-API](https://github.com/pterodactyl/wings/blob/5d9e2c34b6692ae48b1afb1b7bb61694439267b2/router/router.go) bietet keine Datei-Hash-Abfrage. Hashprüfungen übertragen deshalb weiterhin Datei-Inhalte, jetzt mit begrenztem Speicherbedarf. Eine weitere Verlagerung auf den Node würde zusätzliche Wings-Unterstützung benötigen. Größen- oder mtime-Vergleiche ersetzen keine SHA-256-Prüfung.

SFTP und der File Manager teilen die Extension-Sperre nicht. Daher gibt es keinen persistenten Verzeichnis- oder Dateiinhaltscache. Prüfungen vor dem Verschieben, am verschobenen Original und am installierten Ergebnis bleiben erhalten. Unterbrochene Journale einschließlich älterer Recovery-Formate bleiben wiederherstellbar. Backups werden weiterhin nicht automatisch gelöscht.

Die temporäre Ablage liegt auf dem Panel und muss genügend freien Speicherplatz bieten. Streams verwenden die authentifizierte DaemonConnection des Panels einschließlich ihrer TLS- und Timeout-Einstellungen. Gelesene Daten werden auf tatsächliche Größe, eingestellte Grenze und gegebenenfalls Content-Length geprüft.

HTTP-Tests verwenden echte Panel-Middleware und MariaDB mit gefälschten Wings-Dateischnittstellen. Der Chromium-Test verwendet den echten SDK-Testhost und einen HTTP-Adapter-Fake: 120 Zeilen, Maus-/Touch-Sortierung, Hinweis, lokale aufeinanderfolgende Drops, Apply-only und kein horizontaler Überlauf bei 390 Pixeln; keine Seitenfehler. Er prüft nicht die vollständigen Styles des laufenden Panels.

Kein echter Wings-Node oder ETS2-/ATS-Spielprozess wurde getestet. DAT bleibt unverändert; die Spielkompatibilität bearbeiteter Pakete, insbesondere bei Mod-Entfernung, bleibt wie bisher unbewiesen.
