# Installation und Betrieb

Zielversion: DokuWiki **2024-02-06b "Kaos"**. Neuere Releases sind nicht getestet.

## Installation

1. Inhalt von [`plugin/`](../plugin) nach `lib/plugins/reviewqueue/` kopieren.
   Der Verzeichnisname muss exakt `reviewqueue` lauten — DokuWiki leitet den
   Klassennamen daraus ab.
2. Für den Zugriff eines KI-Agenten zusätzlich das
   [`mcp`-Plugin](https://github.com/splitbrain/dokuwiki-plugin-mcp) nach
   `lib/plugins/mcp/` installieren und in der Konfiguration
   `$conf['remote'] = 1` setzen.
3. Konfiguration in *Administration → Konfiguration* (Abschnitt `reviewqueue`)
   oder direkt in `conf/local.php`:

```php
$conf['plugin']['reviewqueue']['review_users']    = 'kail';     // reviewpflichtige Logins
$conf['plugin']['reviewqueue']['review_groups']   = '';         // oder ganze Gruppen
$conf['plugin']['reviewqueue']['reviewer_groups'] = 'reviewer'; // wer freigeben darf
```

Damit das greift, braucht es eine Gruppe `reviewer` (oder einen anderen Namen),
in der die prüfenden Personen sind. **Nicht** die DokuWiki-Admin-Gruppe nehmen:
Review und Wiki-Administration sind bewusst getrennt.

Wichtig: Der reviewpflichtige Benutzer braucht ganz normale Schreibrechte per
ACL. Zurückgehalten wird die Änderung vom Plugin, nicht von der ACL.

## Alltag

**Als Autor mit Review-Pflicht** (typischerweise der KI-Agent): Speichern
funktioniert wie gewohnt, führt aber zu einer Meldung „zur Prüfung eingereicht
als Änderung #N". Die Seite bleibt unverändert, bis jemand freigibt.

**Als Reviewer:** Unter *Site Tools → Review Queue* liegen alle offenen
Änderungen mit Diff, Freigabe- und Ablehnen-Schaltfläche. Auf betroffenen Seiten
erscheint zusätzlich ein Hinweisbanner. Eine Ablehnung sollte begründet werden —
der Text ist für den Autor über die API abrufbar und ist bei einem Agenten die
einzige Chance, es beim nächsten Versuch besser zu machen.

**Alle anderen Benutzer** merken vom Plugin nichts.

## Konflikte

Hat sich die Seite geändert, seit eine Änderung eingereicht wurde, versucht die
Freigabe automatisch einen 3-Wege-Merge. Betreffen die Änderungen verschiedene
Stellen, gehen beide ein. Überschneiden sie sich, wird die Änderung als
*conflicted* markiert und im Review-Formular als Text mit Konfliktmarkern
angeboten; die Marker müssen vor dem Veröffentlichen entfernt werden.

## Wartung

```bash
php bin/plugin.php reviewqueue list      # offene Änderungen
php bin/plugin.php reviewqueue show 42   # eine Änderung inkl. Text
php bin/plugin.php reviewqueue expire    # alte Einträge archivieren
```

Freigeben und Ablehnen gibt es bewusst nicht auf der Kommandozeile: eine
Entscheidung muss einer Person zuzuordnen sein.

`max_pending_age` (Tage) steuert, ab wann `expire` etwas tut; `0` deaktiviert
den Verfall. Sinnvoll als Cronjob, wenn regelmäßig Änderungen liegenbleiben.

## Datenablage und Backup

Alles liegt unter `<savedir>/reviewqueue/`:

```
queue/<id>.json      Metadaten
queue/<id>.content   vorgeschlagener Seitentext
queue/<id>.base      Textstand, auf dem die Änderung basiert (für den Merge)
queue/<id>.media     hochgeladene Datei bei Media-Änderungen
archive/…            dasselbe für entschiedene Änderungen
```

**Dieses Verzeichnis gehört ins Backup.** Wird nur `pages/` gesichert, gehen
offene Änderungen verloren — sie sind ja bewusst noch keine Revision.

## Deinstallation

Das Plugin ist additiv: Nach dem Entfernen speichern alle Benutzer wieder
direkt. Noch offene Änderungen in `queue/` werden dann aber nie mehr
veröffentlicht. Vor dem Entfernen also die Queue leeren — oder das Verzeichnis
aufheben, die Dateien sind reiner Text und lassen sich von Hand übertragen.

## Sicherheitseigenschaften

- **Fail-closed:** Lässt sich die Queue nicht schreiben, wird die Speicherung
  abgelehnt statt durchgelassen.
- **Keine Selbst-Freigabe**, auch nicht durch direktes Absenden des Formulars.
- **Kein ACL-Umweg:** Wer eine Seite nicht lesen darf, sieht auch die dafür
  eingereichten Änderungen nicht — selbst als Reviewer.
- **CSRF-Schutz** über DokuWikis `checkSecurityToken()`.
- Ungeprüfte Inhalte landen weder im Suchindex noch in Feeds oder der
  Versionsgeschichte.
