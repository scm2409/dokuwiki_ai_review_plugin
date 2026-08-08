# Spezifikation: reviewqueue-Plugin

Konsolidiert die Entscheidungen aus [ADR-0001](adr-0001-holdback-vs-hide.md),
[ADR-0002](adr-0002-file-store.md) und [ADR-0003](adr-0003-ki-feedback-remoteexception.md)
zu einer verbindlichen Referenz für die Umsetzung. Verifizierte Fakten zu DokuWiki Kaos
siehe [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md).

## Zustandsmaschine

```
                 ┌──────────────────────────────────────────┐
  Save durch     │                                          │
  review-        v                                          │
  pflichtigen ──> [pending] ──approve──> Merge ok ──> [approved] ──> Live-Revision
  User               │                     │                          (Autor: Original-User)
                      │                     └─ Konflikt ──> [conflicted] ──manuell──> [approved]
                      ├──reject(Grund)──> [rejected]
                      └──neuere Basis macht Change gegenstandslos──> [superseded]
```

Terminal-Zustände: `approved`, `rejected`, `superseded`. Alle drei wandern beim
Erreichen von `queue/` nach `archive/` (siehe Datenmodell).

`superseded` wird gesetzt, wenn ein *neuerer* Pending-Change desselben Users auf
dieselbe Seite entsteht, während ein älterer noch offen ist, **und** der Reviewer
entscheidet, nur den neuesten zu behandeln (siehe Testszenario 6 — standardmäßig bleiben
beide offen und werden sequentiell abgearbeitet; `superseded` ist ein manueller
Reviewer-Schritt, kein Automatismus).

## Datenmodell

```
data/reviewqueue/
├── seq                          # Textdatei mit letzter vergebener ID, io_lock() geschützt
├── queue/
│   ├── <id>.json                 # Metadaten, siehe unten
│   ├── <id>.content              # neuer Volltext der Seite (leer = Löschung)
│   └── <id>.media                # nur bei type=media: Binärdaten des Uploads
└── archive/
    ├── <id>.json
    └── <id>.content               # Content wird mitarchiviert (Nachvollziehbarkeit)
```

### `<id>.json`

| Feld | Typ | Bedeutung |
|---|---|---|
| `id` | int | Fortlaufende ID, aus `seq` |
| `type` | `page` \| `media` | Art der Änderung |
| `target` | string | Page-ID bzw. Media-ID |
| `author` | string | Ursprünglicher Benutzername (z. B. `kail`) |
| `summary` | string | Vom Autor angegebene Zusammenfassung |
| `minor` | bool | Minor-Edit-Flag |
| `baseRev` | int\|null | Zeitstempel der Revision, auf der die Änderung basiert (null bei Neuanlage) |
| `baseHash` | string | Hash des Basistexts, für Merge-Erkennung |
| `created` | int | Unix-Timestamp der Einreichung |
| `state` | `pending`\|`approved`\|`rejected`\|`conflicted`\|`superseded` | Aktueller Status |
| `reviewer` | string\|null | Wer entschieden hat |
| `reviewedAt` | int\|null | Wann entschieden wurde |
| `comment` | string\|null | Ablehnungs-/Freigabe-Kommentar |
| `mergeResult` | `clean`\|`auto-merged`\|`conflict`\|null | Ergebnis des 3-Wege-Merge bei Freigabe |
| `origin` | `ui`\|`remote`\|`cli` | Über welchen Pfad eingereicht |

Alle Schreibzugriffe ausschließlich über DokuWikis `io_saveFile()` / `io_lock()` /
`io_unlock()`. Kein eigenes Locking-Schema.

## Policy: wer ist reviewpflichtig?

Einzige Entscheidungsstelle: `helper/policy.php::needsReview($user, array $groups)`.

```php
$conf['review_users']    = 'kail';   // kommasepariert, exakte Usernamen
$conf['review_groups']   = '';       // kommasepariert, DokuWiki-Gruppen
$conf['reviewer_groups'] = 'reviewer'; // wer freigeben/ablehnen darf
$conf['review_media']    = 1;        // Media-Uploads einbeziehen?
$conf['review_delete']   = 1;        // Löschungen einbeziehen? (technisch: leerer Text)
$conf['auto_merge']      = 1;        // Diff3-Automerge bei Freigabe versuchen?
$conf['show_banner']     = 1;        // Banner auf betroffener Seite für Reviewer?
$conf['max_pending_age'] = 0;        // Tage bis automatisches Archivieren; 0 = kein Verfall
```

`needsReview()` wird als **erste Zeile** in jedem Hook aufgerufen — für nicht
reviewpflichtige Benutzer (der Normalfall, z. B. `martin`) verlässt der Handler die
Funktion sofort, ohne Store, Lock oder I/O zu berühren. Das ist die technische
Umsetzung von "keine Nebenwirkungen für andere Benutzer".

## Hook-Matrix

| Datei | Hook | BEFORE/AFTER | Aufgabe |
|---|---|---|---|
| `action/save.php` | `ACTION_ACT_PREPROCESS` | BEFORE | Reiner Marker (kein `preventDefault()`, keine Datenänderung): merkt sich, dass dieser Request DokuWikis eigener interaktiver `save`-Act ist, damit `handleWikipageSave()` unten zwischen "Mensch am Editor" (freundlicher Hinweis) und jedem anderen Aufrufer (harte `RemoteException`) unterscheiden kann — ganz ohne fragile Skriptname-Erkennung |
| `action/save.php` | `COMMON_WIKIPAGE_SAVE` | BEFORE | **Der eigentliche Interceptionspunkt für alle Pfade** (Browser, Remote-API, MCP, CLI, Fremdplugins) — Queue + `preventDefault()`. Browser → `msg()`; alles andere → `RemoteException` (ADR-0003) |
| `action/media.php` | `MEDIA_UPLOAD_FINISH` | BEFORE | Upload-Bytes in Queue kopieren, `preventDefault()` (Phase 7) |
| `action/banner.php` | `TPL_CONTENT_DISPLAY` | BEFORE | Banner voranstellen (`$event->data` ist der gerenderte HTML-String, per Referenz — siehe `docs/research/kaos-hooks.md`), nur wenn `show_banner` an, aktueller User `isReviewer()` **und** offene Pending-Changes für diese Seite existieren |
| `admin.php` | `AdminPlugin`-Interface | — | `handle()`/`html()` werden von `dokuwiki\Action\Admin::preProcess()` für jeden Request auf die Seite aufgerufen (`handle()` verarbeitet ein Approve/Reject-POST inkl. `checkSecurityToken()` und Selbst-Freigabe-Verbot, danach `html()` für Queue-Liste + Diff). `isAccessibleByCurrentUser()` ist überschrieben auf `isReviewer()` statt DokuWiki-Admin/Manager-Rechte — kein separates `action/review.php` nötig, das ist der native AdminPlugin-Mechanismus dafür |
| `remote.php` | `RemotePlugin`-Interface | — | `listMyPending`, `getStatus`, `getPendingChange`, `listQueue` (nur für Reviewer) (Phase 8) |

### Re-Entrancy

`helper/apply.php` setzt beim Freigeben ein statisches Flag
(`Policy::$applying = true`), bevor es selbst `saveWikiText()`/Media-Save aufruft — der
`COMMON_WIKIPAGE_SAVE`-Handler prüft dieses Flag zuerst und lässt den Call durch, sonst
würde die Freigabe sich selbst wieder in die Queue stellen.

## Freigabe-Ablauf (`helper/apply.php`)

1. Lock auf den Pending-Change-Eintrag.
2. `helper/merge.php`: aktuellen Live-Text laden, mit `baseHash` vergleichen.
   - Unverändert seit `baseRev` → `mergeResult = clean`, Pending-Text wird 1:1 übernommen.
   - Verändert, `auto_merge=1` → `Diff3` versuchen. Sauber → `auto-merged`, gemergter
     Text wird übernommen. Konflikt → `state = conflicted`, Ablauf bricht hier ab,
     Reviewer muss manuell im Review-Editor auflösen (liefert dann direkt den finalen
     Text, kein erneuter Automerge-Versuch).
3. `REMOTE_USER` (bzw. der von `saveWikiText()` verwendete Autor-Kontext) temporär auf
   `author` aus dem Pending-Change setzen.
4. Re-Entrancy-Flag setzen, `saveWikiText($target, $finalText, $summary, $minor)`
   aufrufen (bzw. `media_save()` für `type=media`). Summary wird um einen Freigabe-
   Vermerk ergänzt (z. B. `" (reviewed by martin, #42)"`); die Review-ID landet zusätzlich
   im Changelog-`extra`-Feld für maschinelle Nachvollziehbarkeit.
5. Re-Entrancy-Flag zurücksetzen, `REMOTE_USER` zurücksetzen.
6. Pending-Change-Eintrag nach `archive/` verschieben, `state = approved`,
   `reviewer`/`reviewedAt` setzen.

**Selbst-Freigabe-Verbot:** `admin.php::handle()` prüft `$record['author'] !==
$_SERVER['REMOTE_USER']` vor jeder Approve/Reject-Aktion — auch wenn der Autor zufällig
in `reviewer_groups` wäre.

### Umsetzungsstand (Phase 5)

Implementiert und gegen den echten Container verifiziert: `helper/apply.php` (Schritte
3–6, Schritt 2 aktuell nur der `clean`-Fall — Hash-Vergleich gegen `baseHash`; bei
Abweichung wird `state=conflicted` gesetzt statt automatisch zu mergen), `admin.php`
(Queue-Liste, Diff via `Diff`/`TableDiffFormatter`, Approve/Reject-Formular),
`action/banner.php`. Der `Diff3`-Automerge-Versuch für den `conflicted`-Fall sowie ein
Resolution-Editor dafür kommen in Phase 6.

## Fail-closed (Leitprinzip, siehe `CLAUDE.md`)

Jeder Schreibversuch in die Queue (`helper/store.php::enqueue()`) ist in einen
try/catch eingebettet. Schlägt das Schreiben fehl (Lock-Timeout, Verzeichnis nicht
beschreibbar, JSON-Encoding-Fehler), wird:

- im Browser-Pfad: der Save mit einer Fehlermeldung abgebrochen (kein Fallback auf
  normalen Save!),
- im Remote-Pfad: eine `RemoteException` geworfen,

— in beiden Fällen bleibt der Ausgangszustand der Seite unverändert. Getestet in
Testszenario 17.

## MCP-Sichtbarkeit

`remote.php` implementiert `dokuwiki\Extension\RemotePlugin`. Jede öffentliche Methode
mit DocBlock erscheint automatisch als `plugin_reviewqueue_<methode>`-Tool im `mcp`-Plugin
(siehe `docs/research/kaos-hooks.md`, Abschnitt zum `mcp`-Plugin — `SchemaGenerator`
generiert das automatisch aus `Api::getPluginMethods()`). Kein zusätzlicher
Integrationscode auf unserer Seite nötig, nur saubere DocBlocks für gute
Tool-Beschreibungen.
