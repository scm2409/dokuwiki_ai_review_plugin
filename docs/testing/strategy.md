# Testkonzept

## Ziel

Automatisierte Tests bis auf Ende-zu-Ende-Ebene gegen eine echte DokuWiki-2024-02-06b-
Installation — nicht nur PHP-Unit-Tests gegen isolierte Klassen. Der Kern der
Anforderung ("KI-Änderungen brauchen Review, `martin`s Änderungen nicht") ist ein
Integrationsverhalten über mehrere DokuWiki-Subsysteme (Save-Pipeline, Remote-API,
ACL, Rendering) hinweg und lässt sich nur end-to-end wirklich verifizieren.

## Umgebung

- **Container:** `test/env/Containerfile`, Basis `php:8.2-apache`, DokuWiki aus dem
  offiziellen Tarball `dokuwiki-2024-02-06b.tgz` (nicht aus einem vorgefertigten
  Docker-Hub-Image — deterministischer, erlaubt sauberes Vorbefüllen von `data/`).
  Podman läuft rootless in dieser Umgebung.
- **Plugins im Image:** unser `reviewqueue` (aus `plugin/` gemountet/kopiert) und
  `splitbrain/dokuwiki-plugin-mcp`, auf einen festen Commit gepinnt (kein `master`-Tracking
  im Test-Setup, damit Testläufe reproduzierbar bleiben).
- **Seeding (`test/env/seed/`):**
  - `users.auth.php`: `admin` (DokuWiki-Admin), `martin`/`martin` (Gruppe `reviewer`),
    `kail`/`kail` (Gruppe — keine besondere, review-pflichtig über `review_users=kail`).
  - `acl.auth.php`: `martin` und `kail` haben Schreibrechte auf den Test-Namespace.
  - `local.php`: `$conf['remote'] = 1`, `reviewqueue`-Konfiguration aus `docs/design/spec.md`.
  - ein paar vorbefüllte Testseiten für Änderungs-/Konflikt-Szenarien.
  - API-Tokens für `martin` und `kail` werden bei jedem Testlauf frisch per PHP-CLI
    generiert (`JWT::fromUser()`, siehe `docs/research/kaos-hooks.md`), nicht fest codiert.
- **Reproduzierbarkeit:** `test/env/up.sh` kopiert einen Pristine-`data/`-Snapshot aus
  dem Image in ein frisches Volume, bevor der Container startet — jeder Testlauf beginnt
  im selben Zustand. `test/env/down.sh` räumt auf.

## Werkzeuge

- **Playwright** (Node ist bereits vorhanden) für Browser-Interaktion — getrennte
  `storageState`-Dateien für `martin`, `kail` (soweit browserbasiert relevant) und
  `admin`.
- **HTTP/JSON-RPC-Requests** (über Playwright's `request`-Fixture, kein Browser nötig)
  für die Remote-API- und MCP-Szenarien — echte `mcp.php`-Aufrufe mit Bearer-Token,
  nicht gemockt.
- PHPUnit gegen DokuWikis `_test/`-Harness ist **bewusst kein Teil des ersten Wurfs**
  (siehe Nicht-Ziele in `docs/roadmap.md`), bleibt aber nachrüstbar.

## Szenarienmatrix

### Reviewpflichtiger Pfad (`kail`)

1. Neue Seite anlegen → Seite für alle unsichtbar/nicht existent, Queue-Eintrag mit
   `state=pending` existiert → `martin` gibt frei → Seite live, Autor in der
   Versionsgeschichte ist `kail`.
2. Bestehende Seite ändern → Live-Inhalt bleibt bis zur Freigabe exakt der alte.
3. Seite löschen (leerer Text) → Seite bleibt bis zur Freigabe bestehen → nach Freigabe
   gelöscht.
4. Media-Upload → Datei vor Freigabe nicht abrufbar → nach Freigabe vorhanden, mit `kail`
   als Uploader in der Media-Historie.
5. Ablehnung mit Begründung → Seite unverändert, `kail` kann über
   `plugin.reviewqueue.getStatus` die Ablehnung samt Grund abfragen.
6. Zwei Pending-Changes auf derselben Seite (kurz nacheinander von `kail` eingereicht)
   → beide erscheinen einzeln in der Queue, sequentielle Freigabe funktioniert, der
   zweite Merge berücksichtigt bereits den durch den ersten freigegebenen Stand.

### Nicht-reviewpflichtiger Pfad (`martin`) — Kernanforderung des Projekts

7. `martin` editiert eine Seite → sofort live, **kein** Eintrag in `data/reviewqueue/queue/`
   entsteht, Standard-DokuWiki-Verhalten in jeder messbaren Hinsicht identisch zu einer
   Installation ganz ohne das Plugin.
8. `martin` löscht eine Seite, lädt Medien hoch, nutzt einen Section-Edit → alles wie
   gewohnt, keine Umleitung, kein Banner für ihn selbst.
9. Regressionstest: `review_users` wird leer konfiguriert → auch `kail` schreibt direkt
   (belegt, dass die Policy-Prüfung wirklich die einzige Entscheidungsstelle ist und es
   keine versteckte Sonderbehandlung für den Namen `kail` gibt).

### Konflikte

10. `kail` reicht einen Change auf Basis von Revision X ein; `martin` ändert in der
    Zwischenzeit einen *anderen* Abschnitt derselben Seite → bei Freigabe automatischer
    Diff3-Merge, Ergebnis enthält beide Änderungen, `mergeResult=auto-merged`.
11. `kail` und `martin` ändern denselben Abschnitt überlappend → `state=conflicted` nach
    Freigabeversuch, Review-Editor zeigt Konfliktmarker, manuelle Auflösung durch
    `martin` führt zu `state=approved` mit dem manuell bereinigten Text.

### MCP Ende-zu-Ende

12. Echter MCP-Handshake (`initialize` → `tools/list` → `tools/call`) gegen
    `lib/plugins/mcp/mcp.php` mit Bearer-Token für `kail`. `tools/list` enthält
    `plugin_reviewqueue_listMyPending` u. a. `core_savePage` liefert ein `isError`-Ergebnis
    mit der Review-ID im Text.
13. Derselbe Ablauf mit Token für `martin` → `core_savePage` liefert Erfolg, Seite ist
    sofort live.

### Sicherheit

14. `kail` versucht, den eigenen Pending-Change über `do=reviewqueue_approve`
    freizugeben → abgelehnt (Selbst-Freigabe-Verbot).
15. Ein Benutzer ohne `reviewer_groups`-Mitgliedschaft bekommt weder Zugriff auf die
    Admin-Queue-Seite noch kann er `do=reviewqueue_approve`/`_reject` auslösen.
16. Approve/Reject-Request ohne gültigen `checkSecurityToken()`-Wert wird abgewiesen
    (CSRF-Schutz).
17. Fail-closed: Store-Verzeichnis wird für die Dauer eines Tests nicht beschreibbar
    gemacht → `kail`s Save wird mit Fehler abgelehnt, **nicht** stillschweigend live
    geschaltet.

## Ausführung

```bash
test/env/up.sh          # Container mit frischem data/-Stand hochfahren
npx playwright test     # gesamte Matrix
test/env/down.sh
```

CI-Tauglichkeit (späterer Schritt, nicht Teil der ersten Phasen): dieselben drei Befehle
in einer Pipeline, `test/env/up.sh` mit `--ci`-Flag für nicht-interaktives Warten auf
Healthcheck statt Tail auf Logs.
