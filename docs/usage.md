# Betrieb

Zielversion: DokuWiki **2024-02-06b "Kaos"**. Neuere Releases sind nicht getestet.

Einrichtung — Plugin, Konten, Konfiguration, MCP-Anbindung — steht in
[`../INSTALL.md`](../INSTALL.md). Dieses Dokument beschreibt den laufenden
Betrieb danach.

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

## Aktualisieren und Deinstallieren

Siehe [`../INSTALL.md`](../INSTALL.md). Kernpunkt: vor dem Entfernen die Queue
leeren, sonst werden offene Änderungen nie veröffentlicht.

## Konto des Agenten möglichst eng halten

Das Plugin fängt alle Schreibpfade ab, die DokuWiki selbst anbietet (siehe
[`design/write-path-audit.md`](design/write-path-audit.md)). Zwei Dinge liegen
aber außerhalb seiner Reichweite und sollten über die Konfiguration abgesichert
werden:

- **ACL knapp halten.** Braucht das Konto keine Löschrechte, gib ihm `AUTH_UPLOAD`
  (8) statt `AUTH_DELETE` (16). Änderungen laufen ohnehin über die Queue; enge
  Rechte sind die zweite Verteidigungslinie.
- **`$conf['remoteuser']`** auf die Konten begrenzen, die die Remote-API wirklich
  brauchen. Fremdplugins können eigene schreibende API-Methoden mitbringen, und
  dafür gibt es in DokuWiki keinen Abfangpunkt.

## Sicherheitseigenschaften

- **Fail-closed:** Lässt sich die Queue nicht schreiben, wird die Speicherung
  abgelehnt statt durchgelassen.
- **Keine Selbst-Freigabe**, auch nicht durch direktes Absenden des Formulars.
- **Kein ACL-Umweg:** Wer eine Seite nicht lesen darf, sieht auch die dafür
  eingereichten Änderungen nicht — selbst als Reviewer.
- **CSRF-Schutz** über DokuWikis `checkSecurityToken()`.
- Ungeprüfte Inhalte landen weder im Suchindex noch in Feeds oder der
  Versionsgeschichte.
- **Keine ungeprüften Änderungen:** Seiten anlegen/ändern/löschen sowie
  Media-Uploads *und* -Löschungen laufen alle über die Queue. Aktionen, die
  sich nicht reviewen lassen (etwa Umbenennen durch das `move`-Plugin), werden
  für reviewpflichtige Konten abgewiesen statt ungeprüft ausgeführt.
