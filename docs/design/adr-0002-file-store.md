# ADR-0002: Dateibasierter Store statt sqlite-Plugin

## Status

Akzeptiert (2026-08-08).

## Kontext

Der `approve`-Plugin nutzt `dokuwiki\plugin\sqlite\SQLiteDB` (siehe
[`docs/research/existing-plugins.md`](../research/existing-plugins.md)) für seinen
Zustand. Für unsere Pending-Queue stehen zwei Optionen offen: dieselbe sqlite-Plugin-
Abhängigkeit übernehmen, oder einen eigenen dateibasierten Store analog zu DokuWikis
eigenem Umgang mit Seiten/Attic (`io_saveFile()`, `io_lock()`) bauen.

## Entscheidung

Wir bauen einen **eigenen, dateibasierten Store** unter `data/aireview/`.

## Begründung

- **Keine externe Plugin-Abhängigkeit.** `sqlite` ist selbst ein separat zu
  installierendes, separat zu pflegendes DokuWiki-Plugin. Für ein Plugin, das explizit
  "kein Plugin passt genau" als Ausgangspunkt hat, wollen wir keine zusätzliche
  Fremdabhängigkeit einführen, die selbst wieder Kompatibilitätsfragen zu Kaos aufwirft.
- **Erwartete Datenmenge ist klein.** Ein bis eine Handvoll KI-Benutzer, Pending-Changes
  im Bereich von Dutzenden bis wenigen Hundert gleichzeitig — dafür ist ein
  Verzeichnis-Scan völlig ausreichend performant, eine Datenbank wäre über-engineered.
- **Konsistenz mit DokuWikis eigenem Modell.** Seiten, Attic-Revisionen und Changelogs
  sind in DokuWiki selbst dateibasiert. Ein dateibasierter Queue-Store fügt sich in
  dasselbe Backup-/Restore-/Inspektions-Modell ein (`data/` sichern reicht).
- **Einfachere Tests.** Pending-Changes lassen sich in Tests direkt als Dateien anlegen/
  prüfen, ohne eine SQLite-Datei zu öffnen oder Schema-Migrationen zu pflegen.

## Konsequenzen

- Kein SQL, keine Migrationen. Schema-Änderungen am `<id>.json`-Format müssen das Plugin
  selbst rückwärtskompatibel lesen (fehlende Felder mit Default behandeln).
- Abfragen wie "alle Pending-Changes eines Benutzers" oder "alle offenen Changes für
  Namespace X" sind lineare Verzeichnis-Scans über `queue/*.json`. Bei der erwarteten
  Größenordnung unkritisch; sollte sich das ändern, ist ein Wechsel zu sqlite später
  isoliert im `helper/store.php` möglich, ohne die restliche Plugin-Architektur
  anzufassen.
- Locking über DokuWikis `io_lock()`/`io_unlock()` und eine einfache `seq`-Datei für
  ID-Vergabe — kein eigenes Concurrency-Konzept nötig.
