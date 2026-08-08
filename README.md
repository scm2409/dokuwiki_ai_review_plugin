# dokuwiki-plugin-reviewqueue

Ein DokuWiki-Plugin, das Änderungen bestimmter Benutzer (typischerweise ein KI-Agent
mit eigenem Wiki-Konto und MCP-Zugang) nicht direkt live schaltet, sondern in eine
Review-Queue stellt. Ein Mensch mit Reviewer-Rechten sieht die Änderung als Diff und
gibt sie frei, lehnt sie ab oder bearbeitet sie vor der Freigabe. Alle anderen Benutzer
sind von diesem Prozess vollständig unberührt — sie speichern wie gewohnt direkt.

Zielversion: DokuWiki **Release 2024-02-06b "Kaos"**.

## Warum ein neues Plugin?

Bestehende Lösungen ([`approve`](https://github.com/gkrid/dokuwiki-plugin-approve),
[`publish`](https://github.com/cosmocode/dokuwiki-plugin-publish)) lassen den Save
durchgehen und verstecken nur unfreigegebene Revisionen vor Lesern — und sind dabei
**namespace-** bzw. berechtigungsbasiert, nicht **benutzerbasiert**. Details siehe
[`docs/research/existing-plugins.md`](docs/research/existing-plugins.md).

## Status

Funktionsfähig und vollständig getestet gegen DokuWiki 2024-02-06b: Seiten
anlegen/ändern/löschen und Media-Uploads laufen über die Queue, mit
3-Wege-Merge, Konfliktauflösung, Admin-Oberfläche und MCP-Werkzeugen für den
Agenten. 46 End-to-End-Tests laufen gegen eine echte Installation im Container.

Installation und Betrieb: [`docs/usage.md`](docs/usage.md).
Phasenstand: [`docs/roadmap.md`](docs/roadmap.md).

## Dokumentation

- [`docs/research/`](docs/research/) — Bestandsaufnahme existierender Plugins und
  verifizierte DokuWiki-Kaos-Hook-Punkte
- [`docs/design/spec.md`](docs/design/spec.md) — Zustandsmaschine, Datenformate,
  Konfiguration
- [`docs/design/`](docs/design/) — Architekturentscheidungen (ADRs)
- [`docs/testing/strategy.md`](docs/testing/strategy.md) — Testkonzept und
  Szenarienmatrix
- [`docs/usage.md`](docs/usage.md) — Installation, Betrieb, Backup, Sicherheit
- [`docs/roadmap.md`](docs/roadmap.md) — Phasenplan und Status
- [`skills/`](skills/) — Agent-Skill, der einer KI den Review-Workflow erklärt

## Verzeichnisstruktur

```
plugin/     DokuWiki-Plugin-Quellcode (wird nach lib/plugins/reviewqueue/ installiert)
skills/     Agent-Skill für den Umgang mit der Review-Queue über MCP
test/env/   Podman-Testumgebung mit DokuWiki 2024-02-06b
test/e2e/   Playwright-End-to-End-Tests
docs/       Recherche, Entscheidungen, Spezifikation, Testkonzept, Bedienung
```

## Entwicklung

```bash
test/env/up.sh          # DokuWiki 2024-02-06b im Podman-Container hochfahren
npx playwright test     # Testsuite laufen lassen
test/env/down.sh
```

Details siehe [`docs/testing/strategy.md`](docs/testing/strategy.md).
