# ADR-0001: Hold-back-Queue statt "Save-then-Hide"

## Status

Akzeptiert (2026-08-08).

## Kontext

Zwei grundsätzliche Modelle stehen zur Wahl, um Änderungen review-pflichtiger Benutzer
nicht sofort wirksam werden zu lassen:

1. **Save-then-hide** (wie `approve`/`publish`, siehe
   [`docs/research/existing-plugins.md`](../research/existing-plugins.md)): Der Save
   erzeugt sofort eine echte Revision im Live-Verzeichnis. Ein zusätzlicher Mechanismus
   entscheidet, welche Revision welchem Leser gezeigt wird (Cache-Hook, Render-Hook,
   Revisionsliste, Suche, Feeds müssen alle mitspielen).
2. **Hold-back-Queue:** Der Save wird *vor* dem Schreiben einer Revision abgefangen
   (`COMMON_WIKIPAGE_SAVE` BEFORE, siehe
   [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md)). Der neue Text landet in
   einem separaten Warteschlangen-Speicher. Erst bei Freigabe wird tatsächlich
   `saveWikiText()` erneut aufgerufen und eine echte Revision erzeugt.

## Entscheidung

Wir wählen **Hold-back-Queue**.

## Begründung

- **Geringere Angriffsfläche.** Save-then-hide muss in jeden Lesepfad eingreifen:
  Rendering-Cache (`PARSER_CACHE_USE`), Revisionsliste, Volltextsuche/Indexer, RSS/Atom-
  Feeds, Backlinks. Jeder vergessene Pfad ist ein Leck, über das ungeprüfter Text sichtbar
  wird. Hold-back-Queue hat genau einen Interceptionspunkt: den Save selbst. Alles danach
  ist unverändertes DokuWiki-Verhalten.
- **Null Nebenwirkungen für nicht-reviewpflichtige Benutzer.** Das ist eine explizite
  Anforderung des Projekts (siehe `CLAUDE.md`). Mit Hold-back-Queue betrifft das Plugin
  den Lesepfad überhaupt nicht — es gibt nichts, das für `martin` oder andere Benutzer
  anders aussehen könnte, weil deren Seiten nie durch die Queue laufen.
- **Konsistenz mit der Erwartungshaltung des Nutzers.** "Entwurf, kein Review noch keine
  Wirkung" ist intuitiver als "Revision existiert bereits, aber ist unsichtbar" — Backups,
  Exporte, direkte Dateisystemzugriffe auf `data/pages/` zeigen bei Hold-back-Queue nie
  ungeprüften Text.
- **Bessere Diagnostizierbarkeit für die KI.** Ein fehlgeschlagener/zurückgehaltener Save
  lässt sich als klarer Fehler an den Aufrufer zurückgeben (siehe ADR-0003) — bei
  Save-then-hide meldet die Remote-API einen *erfolgreichen* Save, obwohl der Inhalt nicht
  live ist, was für einen KI-Agenten irreführend wäre.

## Konsequenzen

- Kein Zugriff auf DokuWikis eingebaute Revisionsverwaltung für Pending-Changes — eigener,
  einfacher Store nötig (siehe [ADR-0002](adr-0002-file-store.md)).
- Freigabe muss den ursprünglichen Save *nachträglich* mit korrektem Autor/Zeitstempel-
  Kontext nachbilden (siehe `docs/design/spec.md`, Abschnitt "Freigabe").
- Konflikte (Live-Seite hat sich seit dem Pending-Change weiterentwickelt) müssen explizit
  behandelt werden, weil die Queue nicht Teil der normalen Revisionskette ist — siehe
  3-Wege-Merge in `docs/design/spec.md`.
