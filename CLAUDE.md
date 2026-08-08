# Projektkontext: dokuwiki-plugin-aireview

## Was hier gebaut wird

Ein DokuWiki-Plugin (Zielversion: **Release 2024-02-06b "Kaos"**, fest, kein
Kompatibilitäts-Ziel für neuere Releases), das Speicherungen bestimmter, konfigurierter
Benutzer/Gruppen nicht direkt live schaltet, sondern in eine Review-Queue stellt. Ein
Reviewer gibt frei/lehnt ab/bearbeitet vor Freigabe. Motivierender Anwendungsfall ist ein
KI-Agent mit eigenem DokuWiki-Benutzer (`kail`) über MCP, aber das Plugin selbst ist
benutzerbasiert und nicht KI-spezifisch.

Der vollständige, freigegebene Plan liegt unter
`/home/martin/.claude/plans/ber-neues-projekt-starten-atomic-feigenbaum.md`.
**Bei Unklarheiten dort zuerst nachsehen**, bevor Architekturfragen neu aufgerollt werden.

## Feste Entscheidungen (nicht neu diskutieren, außer der Nutzer bringt es auf)

- **Hold-back-Queue**, nicht "save-then-hide". Kein Eingriff in Rendering/Cache/Suche.
- **Dateibasierter Store** unter `data/aireview/`, keine sqlite-Plugin-Abhängigkeit.
- Scope: Seiten (Anlage/Änderung/Löschung) + Media-Uploads. Kein Namespace-Filter.
- KI-Feedback über `RemoteException` + eigene `plugin.aireview.*`-Remote-Methoden
  (werden vom `mcp`-Plugin automatisch als Tools exponiert).
- Review-UI: Admin-Seite (Queue+Diff) + Banner auf betroffener Seite. Keine E-Mails,
  kein Syntax-Block (bewusst zurückgestellt).
- Konflikte: 3-Wege-Merge via `Diff3` (`inc/DifferenceEngine.php`), bei echtem Konflikt
  Status `conflicted` + manuelle Auflösung.
- **Leitprinzip: fail-closed.** Kann die Queue nicht sauber geschrieben werden, wird der
  Save abgelehnt — niemals durchgelassen. Test dafür ist Pflicht, nicht optional.
- Test-User (bereits vom Nutzer festgelegt): `martin`/`martin` (normaler Reviewer),
  `kail`/`kail` (KI/MCP-User, review-pflichtig).
- E2E-Stack: Playwright gegen einen Podman-Container mit exakt DokuWiki 2024-02-06b.

## Verifizierte Kaos-Fakten (nicht erneut recherchieren)

- `COMMON_WIKIPAGE_SAVE` BEFORE ist preventable — `inc/File/PageFile.php:139`. Deckt
  Browser-UI, XML-RPC, JSON-RPC, MCP, CLI ab. Eventdaten enthalten vollen neuen Seitentext.
- `MEDIA_UPLOAD_FINISH` ist preventable — `inc/media.php:501`.
- `ApiCore::savePage()` gibt immer `true` zurück, kein Event zum Umbiegen des
  Rückgabewerts existiert in Kaos → Feedback an den Caller nur über `RemoteException`.
- API-Tokens: `dokuwiki\JWT::fromUser($user)->getToken()`, Auth via
  `Authorization: Bearer <token>` — `inc/auth.php:199`.
- MCP-Plugin: [`splitbrain/dokuwiki-plugin-mcp`](https://github.com/splitbrain/dokuwiki-plugin-mcp),
  exponiert die gesamte Remote-API automatisch als MCP-Tools über `SchemaGenerator`
  (`OpenAPIGenerator`-Subklasse). Muss in Phase 8 tatsächlich gegen Kaos verifiziert werden.
- Referenz-Klon des `approve`-Plugins (Muster für Plugin-Struktur, NICHT für das
  Review-Modell) lag unter `scratchpad/approve` — beim Recherchieren angelegt, nicht
  Teil des Repos.

## Arbeitsweise in diesem Projekt

- Markdown im Repo ist Single Source of Truth: Recherche in `docs/research/`,
  Entscheidungen als ADRs in `docs/design/`, Spezifikation in `docs/design/spec.md`,
  Testkonzept in `docs/testing/strategy.md`, Phasenstatus in `docs/roadmap.md`.
- **`docs/roadmap.md` vor Beginn einer neuen Session lesen** — dort steht der
  tatsächliche Fortschritt, nicht nur der Plan.
- Ein Branch pro Phase, Commit am Phasenende, `/code-review` vor jedem Merge.
- DokuWiki-Idiome verwenden statt eigener Infrastruktur: `io_saveFile()`, `io_lock()`,
  `io_unlock()`, `Event`/`Doku_Event`, `AdminPlugin`, `RemotePlugin`, `Diff3`,
  `TableDiffFormatter`. Nicht neu erfinden, was der Core schon anbietet.
- Sprache: Code/Kommentare/Commits auf Englisch (DokuWiki-Konvention), Doku im `docs/`-
  Verzeichnis und Kommunikation mit dem Nutzer auf Deutsch, `lang/de/` und `lang/en/`
  beide vollständig pflegen.

## Testumgebung

DokuWiki 2024-02-06b läuft in einem Podman-Container (`test/env/`), gebaut aus dem
offiziellen Tarball, nicht aus einem Docker-Hub-Image, für deterministisches Seeding.
`test/env/up.sh` erzeugt bei jedem Lauf einen frischen `data/`-Stand. Playwright-Tests
in `test/e2e/` nutzen getrennte Storage-States für `martin` und `kail` und decken sowohl
den Browser-Pfad als auch den JSON-RPC/MCP-Pfad ab. Die vollständige Szenarienliste
(17 Szenarien) steht in `docs/testing/strategy.md`.
