# Verifizierte DokuWiki-Kaos-Hook-Punkte (Release 2024-02-06b)

Alle Angaben gegen den Git-Tag `release-2024-02-06b` im offiziellen Repo
<https://github.com/splitbrain/dokuwiki> geprüft (Commit `aad0b49e48318eb343208b2c865291716c1819b3`).
Ein lokaler Klon liegt zu Referenzzwecken (nicht Teil des Repos) unter `scratchpad/kaos/`.

## Seiten speichern: `COMMON_WIKIPAGE_SAVE`

Zentrale Funktion `saveWikiText()` in `inc/common.php:1296` delegiert an
`PageFile::saveWikiText()` in `inc/File/PageFile.php`. Dort (Zeile ~79–139):

```php
public function saveWikiText($text, $summary, $minor = false)
{
    // ... $data zusammenstellen: newContent, changeType, summary, minor, ...
    $data['page'] = $this; // Event-Handler bekommen Zugriff auf die PageFile-Instanz
    $event = new Event('COMMON_WIKIPAGE_SAVE', $data);
    if (!$event->advise_before()) return;   // <-- PREVENTABLE
    if (!$data['contentChanged']) return;
    // ... schreibt io_writeWikiPage(), legt Attic-Kopie an, Changelog-Eintrag
    $event->advise_after();
}
```

**Das ist der zentrale Interceptionspunkt.** `$event->preventDefault()` im BEFORE-Handler
verhindert zuverlässig, dass irgendetwas geschrieben wird — unabhängig davon, ob der Save
über die Browser-UI, XML-RPC, JSON-RPC, das MCP-Plugin oder ein CLI-Skript ausgelöst wurde,
weil alle diese Pfade letztlich `saveWikiText()` aufrufen.

Wichtig für die Section-Edit-Korrektheit: `$data['newContent']` enthält bereits den
**vollständigen** neuen Seitentext (Section-Merging ist vorher passiert), nicht nur den
geänderten Abschnitt. Die Queue muss daher keine Sonderbehandlung für Section-Edits haben.

`$data['changeType']` unterscheidet `DOKU_CHANGE_TYPE_CREATE` / `_EDIT` / `_MINOR_EDIT` /
`_DELETE` / `_REVERT` — Löschungen laufen über denselben Hook (leerer `newContent`).

## Remote-API-Pfad: `ApiCore::savePage()`

`inc/Remote/ApiCore.php:660` (`savePage`) und `:723` (`appendPage`, ruft intern `savePage`
auf) rufen am Ende schlicht die globale Funktion `saveWikiText($page, $TEXT, $summary,
$isminor)` auf (Zeile 696) und geben danach hart `return true;` zurück (Zeile 702).

**Konsequenz:** Es gibt in Kaos kein Event, mit dem sich der Rückgabewert von
`core.savePage` nachträglich verändern lässt — `Api::call()` in `inc/Remote/Api.php`
ruft `$methods[$method]($args)` direkt auf und reicht das Ergebnis unverändert durch, ohne
einen dazwischenliegenden, preventable Event. Der einzige Weg, den Aufrufer (die KI) zu
informieren, dass der Save *nicht* live ist, ist eine **Exception** aus dem
`COMMON_WIKIPAGE_SAVE`-BEFORE-Handler heraus — die propagiert über `Api::call()` bis zum
JSON-RPC-Server und wird dem Client als Fehler zugestellt (siehe ADR-0003).

## Media-Uploads: `MEDIA_UPLOAD_FINISH`

`inc/media.php`, Funktion `media_upload_finish()` (~Zeile 419–501):

```php
// Event data:
// $data[0] fn_tmp, $data[1] fn, $data[2] id, $data[3] imime,
// $data[4] overwrite, $data[5] move (Callback-Name für move/copy)
return Event::createAndTrigger('MEDIA_UPLOAD_FINISH', $data, '_media_upload_action', true);
```

`Event::createAndTrigger()` mit `$canPreventDefault = true` (letztes Argument) — ebenfalls
preventable. Für die Queue muss die temporäre Datei (`$data[0]`) vor dem Verwerfen des
Requests kopiert werden, da DokuWiki sie danach aufräumt.

## Diff / 3-Wege-Merge: `Diff3`

`inc/DifferenceEngine.php:1319` — `class Diff3 extends Diff`. Nimmt drei Textarrays
(Basis, "mine", "yours") und liefert Konfliktblöcke bzw. den gemergten Text.
Darstellung über `TableDiffFormatter` (Zeile 1120) und `InlineDiffFormatter` (Zeile 1234)
in derselben Datei — dieselben Klassen, die DokuWikis eigene Revisions-/Diff-Ansicht
(`inc/Ui/Diff.php`, `inc/Ui/PageDiff.php`) nutzt.

## API-Token / Authentifizierung

- `dokuwiki\JWT` (`inc/JWT.php`): `JWT::fromUser($user)` erzeugt ein Token-Objekt,
  `->getToken()` liefert den String. Im Benutzerprofil (`inc/Ui/UserProfile.php:172`)
  über `do=authtoken` erreichbar — heißt: **auch per PHP-CLI-Skript generierbar**, was für
  automatisiertes Seeding im Testcontainer wichtig ist.
- `inc/auth.php:199` (`auth_tokenlogin()`): akzeptiert `Authorization: Bearer <token>`
  (und laut MCP-Plugin-Code zusätzlich einen `X-DOKUWIKI-TOKEN`-Header).
- `conf/dokuwiki.php:68-70`: `$conf['remote']` muss `1` sein, `$conf['remoteuser']` steuert,
  wer die Remote-API überhaupt nutzen darf (leer = alle).

## `mcp`-Plugin (Andreas Gohr, `splitbrain/dokuwiki-plugin-mcp`)

- `mcp.php` (Einstiegspunkt) instanziiert `McpServer` (extends `JsonRpcServer`) und ruft
  `->serve()`. Fehler werden über `returnError()` in eine MCP-konforme Fehlerantwort
  übersetzt.
- `McpServer::mcpToolsList()` liefert `SchemaGenerator::getTools()` — generiert
  automatisch **aus jeder registrierten Remote-API-Methode** (Core + alle
  `RemotePlugin`-Implementierungen) ein MCP-Tool, inklusive JSON-Schema aus
  `OpenAPIGenerator::getMethodArguments()`. Das heißt: sobald unser `remote.php`
  (Phase 8) `RemotePlugin` implementiert, tauchen `plugin.reviewqueue.*`-Methoden
  automatisch als MCP-Tools auf — **ohne** Änderung am `mcp`-Plugin.
- Abhängigkeiten von `McpServer`/`SchemaGenerator` gegen Kaos geprüft: `ApiCall`,
  `ApiCall::getCategory()`, `::getSummary()`, `::getArgs()`, `JsonRpcServer`,
  `AccessDeniedException`, `RemoteException`, `OpenAPIGenerator::getMethodArguments()`
  — alle in `inc/Remote/` bzw. `inc/Remote/OpenApiDoc/` von Kaos vorhanden. Der Plugin-Code
  selbst benötigt keine erkennbaren Kaos-inkompatiblen Sprachfeatures. **Muss in Phase 8
  dennoch tatsächlich im Container verifiziert werden** — statische Prüfung ersetzt keinen
  Lauftest.
- `plugin.info.txt` des mcp-Plugins ist auf `2026-08-04` datiert (neuer als Kaos selbst)
  — Grund mehr, es in Phase 8 gegen Kaos tatsächlich zu testen statt nur zu vertrauen.

## Weitere für später relevante Fundstellen

- `inc/Extension/RemotePlugin.php`, `AdminPlugin.php` — Basis-Interfaces für `remote.php`
  bzw. `admin.php` unseres Plugins.
- `inc/Ui/PageConflict.php`, `inc/Ui/PageDraft.php` — DokuWiki-eigene UI-Bausteine für
  Konflikt- bzw. Entwurfsanzeige, ggf. als Vorlage für unsere Review-Editor-Ansicht bei
  `conflicted`-Status.
- `_test/phpunit.xml` sammelt automatisch `../lib/plugins/*/_test/` ein — falls PHPUnit
  später ergänzt wird (siehe Nicht-Ziel in der Roadmap), braucht unser Plugin dafür nur
  ein `_test/`-Verzeichnis, keine weitere Konfiguration.

## Bug in Kaos: `Diff3::mergedOutput()` ist unbenutzbar

Beim Umsetzen des 3-Wege-Merges (Phase 6) gefunden. `Diff3::mergedOutput()`
(`inc/DifferenceEngine.php:1357`) greift im Konfliktzweig direkt auf
`$edit->final1` / `$edit->final2` zu, doch diese Properties sind auf `_Diff3_Op`
als `protected` deklariert (Zeile 1458 ff.). Sobald tatsächlich ein Konflikt
auftritt, endet der Aufruf in einem Fatal Error:

```
Error: Cannot access protected property _Diff3_Op::$final1
```

Bei konfliktfreien Merges passiert das nicht, weil dort nur das öffentliche
`merged()` verwendet wird — der Automerge-Pfad funktioniert also.

Der Core ruft `mergedOutput()` nirgends auf; `Diff3` fehlt sogar in der
Autoload-Map (`inc/load.php:45-47` listet nur `Diff`, `UnifiedDiffFormatter`
und `TableDiffFormatter`), weshalb die Klasse ohne explizites
`require_once(DOKU_INC . 'inc/DifferenceEngine.php')` gar nicht erst geladen
wird. Deshalb ist der Defekt upstream nie aufgefallen.

Umgehung im Plugin (`helper/merge.php`): die Edit-Liste selbst durchlaufen
(`$diff3->_edits`, `isConflict()` und `merged()` sind öffentlich zugänglich)
und die beiden Konfliktseiten per Reflection lesen. Das bleibt auch dann
funktionsfähig, wenn eine spätere DokuWiki-Version die Sichtbarkeit korrigiert.
