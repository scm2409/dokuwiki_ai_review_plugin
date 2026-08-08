# ADR-0004: Sichtbarkeit offener Änderungen für den Autor

## Status

Akzeptiert (2026-08-08).

## Kontext

Aus dem Hold-back-Modell ([ADR-0001](adr-0001-holdback-vs-hide.md)) folgt, dass eine
eingereichte Änderung **nirgends** als Seiteninhalt existiert, bevor sie freigegeben ist.
Das wurde gegen den laufenden Container verifiziert — nach einer eingereichten Änderung
von `kail`:

| Was `kail` tut | Was er bekommt |
|---|---|
| `core.getPage` auf die Seite | den **Live-Text**, nicht seinen Entwurf, ohne jeden Hinweis |
| `core.searchPages` nach einem Wort aus seinem Entwurf | **kein Treffer** |
| `core.searchPages` nach einem Wort aus dem Live-Text | normaler Treffer |
| Seite im Browser öffnen | Live-Fassung, kein Banner (der ist nur für Reviewer) |

Für die Isolation ist das genau richtig — ungeprüfter Text darf weder im Rendering noch
im Suchindex auftauchen. Für den **Autor** ist es aber eine echte Falle, und zwar eine,
die stillen Datenverlust verursacht:

1. `kail` reicht Änderung #1 ein (Seite komplett umgeschrieben).
2. `kail` liest die Seite erneut → sieht den alten Live-Text, hält seine Arbeit für
   verloren oder nie passiert.
3. `kail` schreibt erneut, diesmal auf Basis des Live-Texts → Änderung #2.
4. Beide Einträge tragen **denselben `baseHash`** (verifiziert), basieren also beide auf
   der Live-Fassung und nicht aufeinander.
5. Gibt der Reviewer beide frei, überschreibt #2 die Arbeit aus #1 spurlos. Gibt er nur
   #1 frei, wird #2 `conflicted`.

Das betrifft nicht nur KI-Agenten — ein Mensch mit Review-Pflicht läuft in dieselbe Falle.

## Entscheidung

Der **Lesepfad bleibt unangetastet** (ADR-0001 gilt unverändert): kein Eingriff in
Rendering, Cache, Suchindex oder Revisionsliste, auch nicht für den Autor selbst.
Stattdessen bekommt der Autor **explizite Werkzeuge und explizite Warnungen**:

1. **`plugin.reviewqueue.getPageToEdit($page)`** — der zentrale Baustein. Liefert den
   Text, auf dem weitergearbeitet werden soll: den eigenen neuesten offenen Entwurf,
   falls vorhanden, sonst die Live-Fassung. Dazu `source` (`pending`/`live`),
   `pendingId` und ein `warning`-Feld. Ein Aufruf, der immer das Richtige tut.
2. **`listMyPending()` / `getStatus($id)` / `getPendingText($id)`** — eigene offene
   Änderungen auflisten, Status samt Ablehnungsgrund abfragen, eingereichten Text
   zurücklesen.
3. **Warnung beim Stapeln.** Reicht jemand eine Änderung für eine Seite ein, für die er
   bereits offene Änderungen hat, nennt die Rückmeldung diese explizit beim Namen
   („you already have unreviewed change(s) #1, #2 on this page … the earlier work will be
   overwritten"). Im Browser als zusätzliche Meldung, über die Remote-API angehängt an
   die ohnehin geworfene `RemoteException` ([ADR-0003](adr-0003-ki-feedback-remoteexception.md)).
4. **Warnung für den Reviewer.** Liegen mehrere offene Änderungen für dieselbe Seite vor,
   weist die Admin-Queue bei jedem betroffenen Eintrag darauf hin, dass diese nicht
   aufeinander aufbauen und ein Mehrfach-Freigeben die früheren überschreibt.
5. **Der Agent-Skill** (`skills/dokuwiki-reviewqueue/`) schreibt den Ablauf verbindlich
   fest: vor jedem Edit `getPageToEdit` statt `core.getPage`.

Bewusst **nicht** umgesetzt: automatisches `superseded`-Setzen des älteren Eintrags. Der
Reviewer könnte den älteren Entwurf inhaltlich bevorzugen; die Entscheidung bleibt beim
Menschen (konsistent mit `docs/design/spec.md`, wo `superseded` ein manueller Schritt ist).

## Begründung

- **Der Lesepfad ist die teure Stelle.** Ihn für den Autor zu öffnen hieße, genau die
  Eingriffe nachzubauen, die ADR-0001 vermeiden wollte (Cache, Suche, Feeds, Revisionen) —
  nur eben benutzerabhängig, was die Sache schwerer testbar und leichter leck-anfällig
  macht statt einfacher.
- **Werkzeuge sind für einen Agenten der natürlichere Kanal.** Der MCP-Client sieht die
  Tool-Beschreibungen ohnehin; ein Tool namens „get the page text to base an edit on" mit
  einer unmissverständlichen Beschreibung steuert das Verhalten zuverlässiger als ein
  impliziter Zustand, den der Agent erraten müsste.
- **Warnungen fangen den Fall ab, in dem das Werkzeug nicht benutzt wurde.** Selbst ein
  Agent, der `getPageToEdit` ignoriert, bekommt beim Einreichen eine unübersehbare
  Meldung, und der Reviewer bekommt sie ebenfalls. Kein Pfad führt still in den
  Datenverlust.

## Konsequenzen

- Jeder Client, der schreiben will, sollte `getPageToEdit` verwenden. `core.getPage`
  bleibt funktionsfähig und liefert weiterhin die Live-Fassung — das ist für reine
  Leseanwendungen auch korrekt.
- Die Warnungen sind Text, keine Blockade: ein Autor *darf* mehrere Änderungen stapeln
  (etwa bewusst als Alternativvorschläge). Die Verantwortung liegt beim Reviewer, der
  die Warnung ebenfalls sieht.
- **Docblock-Falle:** DokuWikis Parser (`inc/Remote/OpenApiDoc/DocBlock.php:49`) entfernt
  bei `@param`/`@return`/`@throws` nur die *erste* Zeile. Mehrzeilige Tags lecken in die
  generierte MCP-Tool-Beschreibung und erzeugen unbrauchbare Bruchstücke (im ersten Wurf
  passiert und korrigiert). Tags müssen einzeilig bleiben; Strukturbeschreibungen gehören
  in den Prosateil davor.
- Die Lese-Tools werden vom `mcp`-Plugin als `readOnlyHint: false` annotiert, weil dessen
  `READ_ONLY`-Liste (`SchemaGenerator`) nur Core-Methoden kennt. Kosmetisch — ein Client
  fragt eventuell unnötig nach Bestätigung. Nicht änderbar ohne Patch am `mcp`-Plugin,
  daher akzeptiert.
