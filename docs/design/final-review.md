# Abschluss-Review (2026-08-09)

Vollständige Durchsicht nach Fertigstellung aller Phasen, wie vom Nutzer
angefordert. Ergänzt [`code-review.md`](code-review.md) (Zwischenstand) und
[`../testing/coverage-review.md`](../testing/coverage-review.md).

## Systematische Prüfungen

| Prüfung | Ergebnis |
|---|---|
| Sprachschlüssel: verwendet vs. definiert, `en` vs. `de` | vollständig deckungsgleich, keine Waisen |
| Konfigurationsschlüssel: `default.php` vs. `metadata.php` vs. `settings.php` vs. Code | vollständig deckungsgleich |
| PHP-Syntax aller Dateien gegen PHP 8.2 | fehlerfrei |
| Ausgabe-Escaping | durchgehend `hsc()`; Diff und Formulare über Core-Klassen |
| Eingaben aus Requests | IDs durchgehend `int`-gecastet, kein Path-Traversal möglich |
| Testsuite | 46 Tests, deterministisch, gegen echte Installation |

## Beim Abschluss-Review behoben

### 1. Banner ignorierte konfliktbehaftete Änderungen

`action/banner.php` filterte auf `state = pending`. Eine Änderung im Zustand
`conflicted` — also gerade die, die Aufmerksamkeit braucht — erzeugte keinen
Hinweis mehr auf der betroffenen Seite. Jetzt zählen beide Zustände.

### 2. Banner prüfte nur die Reviewer-Rolle, nicht die Seitenrechte

Nach dem Schließen der ACL-Lücke in Phase 9 war der Banner die letzte Stelle,
die noch `isReviewer()` statt `mayReviewTarget()` benutzte. Praktisch unkritisch
(der Banner erscheint nur auf einer Seite, die der Betrachter ohnehin gerade
liest), aber inkonsistent — angeglichen.

### 3. `searchMyPending` prüfte Media mit Seitenrechten

Für Einträge vom Typ `media` wurde `AUTH_READ` auf die Media-ID geprüft statt
`AUTH_UPLOAD`. Angeglichen an `mayReviewTarget()`.

### 4. Verwaiste Queue-Einträge bei fehlgeschlagenem Upload

Schlug in `action/media.php` das Kopieren der Datei *nach* dem Anlegen des
Datensatzes fehl, blieb ein Eintrag ohne Nutzdaten zurück, der bei der Freigabe
zwangsläufig scheitert. Er wird jetzt über `store::discard()` entfernt —
bewusst gelöscht statt archiviert, weil es keine menschliche Entscheidung gibt,
die man festhalten müsste.

### 5. Zwei Tests konnten falsch-positiv bestehen

- „kail sieht die Admin-Queue nicht" hätte auch bei leerer Queue bestanden und
  damit nichts belegt. Der Test legt jetzt zuerst eine Änderung mit eindeutigem
  Markertext an und prüft zusätzlich auf dessen Abwesenheit.
- Der CSRF-Test prüfte nur, dass die Zielseite unverändert blieb — das wäre auch
  bei einem aus anderem Grund fehlgeschlagenen Approve der Fall gewesen. Er
  prüft jetzt zusätzlich, dass die Änderung noch `pending` ist.

## Über den ganzen Projektverlauf gefundene echte Fehler

Zur Erinnerung, weil sie zeigen, wo die Fallstricke in diesem Umfeld liegen —
alle behoben und durch Tests abgesichert:

1. **Verwaister Seiten-Lock** nach abgefangenem Remote-Save: `martin` wurde aus
   genau den Seiten ausgesperrt, an denen der Agent arbeitete.
2. **Freigegebene Seiten landeten nicht im Suchindex** — live, aber unauffindbar.
3. **`Diff3::mergedOutput()` ist in Kaos defekt** (Zugriff auf `protected`
   Properties); zusätzlich fehlt `Diff3` in DokuWikis Autoload-Map.
4. **Basistext aus dem Attic ist unzuverlässig** (Sekundengranularität der
   Revisionen) — wird jetzt mitgespeichert.
5. **`$conf['savedir']` ist ein relativer Pfad**, der je nach Einstiegsskript
   anders auflöst.
6. **Mehrzeilige Docblock-Tags** zerstören die generierten MCP-Tool-Beschreibungen.
7. **ACL-Umgehung über die Queue** durch Reviewer ohne Leserecht.

## Bewusst offen

- **`replaySave()` setzt `REMOTE_USER`, nicht `$USERINFO`.** Für Attribution,
  Changelog und Benachrichtigungen verifiziert korrekt. Ein Fremdplugin, das in
  `COMMON_WIKIPAGE_SAVE` auf `$USERINFO['grps']` schaut, sähe während der
  Freigabe die Gruppen des Reviewers. Kein bekannter Fall; eine Korrektur würde
  bedeuten, den kompletten Benutzerkontext zu tauschen, was neue Risiken schafft.
- **Kein PHPUnit.** Die Logik ist fast durchgehend Integrationsverhalten gegen
  DokuWiki-Interna; die End-to-End-Tests decken sie realitätsnäher ab. Für reine
  Einheiten (`helper/merge.php`) wären Unit-Tests sinnvoll, falls die
  Merge-Logik wächst — `_test/` wird von DokuWikis Harness automatisch gefunden.
- **Keine Behandlung von Seiten-Umbenennungen** durch das `move`-Plugin. Wird
  eine Seite umbenannt, während eine Änderung für sie offen ist, zielt diese
  weiterhin auf die alte ID und wird bei der Freigabe als Neuanlage behandelt.
  Sauber wäre ein Hook auf `PLUGIN_MOVE_PAGE_RENAME`; das setzt aber voraus,
  dass das `move`-Plugin überhaupt installiert ist, und war nie Teil des Umfangs.
- **Kompatibilität nur mit 2024-02-06b** verifiziert. Die genutzten Hooks
  existieren in 2025-05-14 und 2026-07-14 unverändert, aber der `Diff3`-Defekt
  könnte dort behoben sein — die Umgehung ist darauf vorbereitet, ein Testlauf
  gegen eine neuere Version stünde aber noch aus.
