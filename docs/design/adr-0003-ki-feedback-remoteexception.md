# ADR-0003: KI-Feedback über RemoteException statt stillen Erfolg

## Status

Akzeptiert (2026-08-08).

## Kontext

`ApiCore::savePage()` gibt in Kaos unbedingt `true` zurück
(`inc/Remote/ApiCore.php:702`, siehe [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md)).
Es gibt kein Event, mit dem sich dieser Rückgabewert nachträglich verändern lässt. Wenn
unser `COMMON_WIKIPAGE_SAVE`-BEFORE-Handler den Save in die Queue umlenkt und dann
kommentarlos `preventDefault()` aufruft, kehrt `savePage()` trotzdem `true` zurück — die
KI (und jeder andere Remote-API-Aufrufer) würde glauben, die Änderung sei live.

## Entscheidung

Der BEFORE-Handler wirft im Remote-/CLI-Kontext (erkennbar daran, dass der Aufruf nicht
über die reguläre Browser-Action läuft) eine `\dokuwiki\Remote\RemoteException` mit einer
sprechenden Nachricht und der Review-ID, z. B.:

> "Change queued for review as #42 (approval required for user 'kail'). The page was
> NOT modified. Use plugin.reviewqueue.getStatus to check its state."

Zusätzlich stellt `remote.php` eigene Methoden bereit (`listMyPending`, `getStatus`,
`getPendingChange`), die automatisch als MCP-Tools erscheinen (siehe
[`docs/research/kaos-hooks.md`](../research/kaos-hooks.md), Abschnitt zum `mcp`-Plugin),
damit die KI aktiv den Status und ggf. eine Ablehnungsbegründung abfragen kann.

Im **Browser-UI-Pfad** (`ACTION_ACT_PREPROCESS`) wird stattdessen kein Fehler geworfen,
sondern auf eine eigene Bestätigungsseite umgeleitet ("Ihre Änderung wurde zur Prüfung
eingereicht") — ein Menschen an der Browser-Oberfläche soll keine rohe RPC-Exception sehen.

## Begründung

- **Fail-loud statt fail-silent gegenüber der KI.** Ein Agent, der einen erfolgreichen
  Save annimmt, könnte dem Nutzer fälschlich "erledigt" melden. Eine harte Exception ist
  für einen Agenten die zuverlässigste Signalform — sie lässt sich nicht versehentlich
  ignorieren wie ein Rückgabewert, der zufällig auch bei Erfolg `true` wäre.
- **Kein Bruch der Remote-API-Verträge für alle anderen Methoden.** Wir ändern nichts an
  `ApiCore` selbst (nicht patchbar, nicht unser Code) — die Exception kommt sauber aus
  unserem eigenen Event-Handler.
- **Zwei getrennte UX-Pfade, weil die Zielgruppen unterschiedlich sind.** Menschen an der
  Web-UI erwarten eine verständliche Seite, keine JSON-RPC-Fehlermeldung; Remote-Clients
  (die KI) erwarten einen strukturierten Fehler, keine HTML-Seite.

## Konsequenzen

- Der Handler muss zuverlässig unterscheiden können, ob der aktuelle Request über die
  Browser-Action oder über die Remote-API läuft (z. B. `$INPUT->server->str('REQUEST_METHOD')`
  in Kombination mit dem DokuWiki-Actionkontext, oder ein Flag, das `mcp.php`/`XmlRpcServer`/
  `JsonRpcServer` beim Bootstrap setzen — Detailentscheidung in Phase 4 anhand des
  tatsächlichen Request-Kontexts, den Kaos zur Verfügung stellt).
- `plugin.reviewqueue.*`-Methoden müssen so gestaltet sein, dass sie über
  `SchemaGenerator::getTools()` sinnvolle Beschreibungen bekommen (kurze `@param`/
  `@return`-Docblocks, wie bei `ApiCore`-Methoden üblich), damit sie als MCP-Tools
  verständlich sind.
- Getestet in Szenario 12/13 der Testmatrix (`docs/testing/strategy.md`): echter
  MCP-Handshake, `core_savePage` liefert für `kail` einen Fehler mit Review-ID, für
  `martin` geht der Aufruf normal durch.
