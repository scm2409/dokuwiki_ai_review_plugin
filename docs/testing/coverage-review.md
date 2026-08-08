# Review der Testabdeckung (2026-08-08)

Abgleich der vorhandenen Playwright-Tests gegen die Szenarienmatrix in
[`strategy.md`](strategy.md). Ergebnis des Reviews, den der Nutzer angefordert hat.

## Befund

| # | Szenario | Status vor Review | Maßnahme |
|---|---|---|---|
| 1 | Neue Seite → Freigabe → live | teilweise — Freigabe getestet, **Autorenzuweisung an `kail` nicht** | Test ergänzt |
| 2 | Seite ändern → live-Inhalt unverändert | ✅ | — |
| 3 | Löschung → Freigabe → gelöscht | ❌ **gar nicht getestet** | Test ergänzt |
| 4 | Media-Upload | ❌ nicht implementiert (Phase 7) | offen, bewusst |
| 5 | Ablehnung mit Begründung, abrufbar | ✅ | — |
| 6 | Zwei Änderungen, sequentielle Freigabe | teilweise — Warnung getestet, **zweite Freigabe nicht** | Test ergänzt |
| 7 | `martin` editiert → sofort live | ✅ | — |
| 8 | `martin` löscht / Section-Edit | ❌ nicht getestet | Test ergänzt (Löschung) |
| 9 | `review_users` leer → auch `kail` schreibt direkt | ❌ **gar nicht getestet** | Test ergänzt |
| 10 | Automerge disjunkter Änderungen | ❌ nicht implementiert (Phase 6) | offen, bewusst |
| 11 | Konflikt → `conflicted` | ❌ **implementiert, aber ungetestet** | Test ergänzt |
| 12 | MCP `tools/list` enthält `plugin_reviewqueue_*` | ❌ nur manuell geprüft | Test ergänzt |
| 13 | `martin` über MCP → geht durch | ❌ nicht getestet | Test ergänzt |
| 14 | Selbst-Freigabe verboten | ❌ **gar nicht getestet** (Sicherheit!) | Test ergänzt |
| 15 | Nicht-Reviewer kein Zugriff | ✅ | — |
| 16 | CSRF | ✅ | — |
| 17 | Fail-closed | ❌ **gar nicht getestet**, obwohl in `CLAUDE.md` als „Pflicht, nicht optional" deklariert | Test ergänzt |

## Bewertung

Die Lücken lagen systematisch dort, wo ich beim Bauen **manuell** verifiziert und
das Ergebnis gesehen hatte (Autorenzuweisung, MCP-Tool-Liste, Löschung) — die
Bestätigung am Terminal hat den fehlenden Test verdeckt. Sicherheitsrelevant und
besonders unangenehm waren drei davon:

- **Selbst-Freigabe (14)** war ungetestet, obwohl es die Kernabsicherung gegen
  einen Agenten ist, der seine eigenen Änderungen durchwinkt.
- **Fail-closed (17)** war ungetestet, obwohl es das ausdrücklich formulierte
  Leitprinzip des Projekts ist.
- **`conflicted` (11)** ist bereits ausgeführter Code (`helper/apply.php`
  vergleicht `baseHash`) — ungetesteter, aber aktiver Code ist schlechter als
  noch nicht geschriebener.

`review_users` leer (9) ist der Test, der die eigentliche Projektanforderung
belegt — dass die Review-Pflicht rein an der Konfiguration hängt und es keine
versteckte Sonderbehandlung des Namens `kail` gibt.

## Nachtrag nach Abschluss von Phase 6, 7 und 9

Die zum Zeitpunkt des ersten Reviews noch offenen Szenarien sind inzwischen
implementiert **und** getestet:

| # | Szenario | Test |
|---|---|---|
| 4 | Media-Upload → Freigabe | `media.martin.spec.ts` — inkl. sha256-Vergleich der ausgelieferten Datei gegen das Original, damit eine Beschädigung von Binärdaten nicht stillschweigend durchgeht |
| 8 | `martin` lädt Medien hoch | `media.martin.spec.ts` |
| 10 | Automerge disjunkter Änderungen | `merge.martin.spec.ts` |
| 11 | Konflikt → manuelle Auflösung | `merge.martin.spec.ts` — inkl. Ablehnung von Text, der noch Konfliktmarker enthält |
| — | ACL-Isolation (Befund A aus dem Code-Review) | `acl.martin.spec.ts` — mit echtem, restriktivem Namespace statt der freizügigen Test-ACL |

Zusätzlich beim Abschlussreview zwei Tests nachgeschärft, die falsch-positiv
bestehen konnten:

- „kail sieht die Admin-Queue nicht" hätte auch bei **leerer** Queue bestanden.
  Der Test legt jetzt erst eine Änderung mit eindeutigem Markertext an.
- Der CSRF-Test prüfte nur, dass die Seite unverändert ist — das wäre auch bei
  einem aus ganz anderen Gründen fehlgeschlagenen Approve der Fall. Er prüft
  jetzt zusätzlich, dass die Änderung noch `pending` ist.

## Nicht behoben (bewusst)
- Szenario 8 „Section-Edit" ist nur indirekt abgedeckt: `COMMON_WIKIPAGE_SAVE`
  bekommt laut [`kaos-hooks.md`](../research/kaos-hooks.md) immer den
  vollständigen Seitentext, Section-Edits sind daher kein Sonderfall im
  Plugin-Code. Ein eigener Test wäre reine Zeremonie.
