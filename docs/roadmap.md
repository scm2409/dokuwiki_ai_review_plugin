# Roadmap

Statusanker über Sessions hinweg. Vor Beginn einer neuen Arbeitssitzung hier lesen.

| # | Phase | Status | Branch | Notizen |
|---|---|---|---|---|
| 0 | Repo-Bootstrap | ✅ erledigt | `main` | git init, Grundgerüst, CLAUDE.md |
| 1 | Recherche dokumentieren | ✅ erledigt | `main` | existing-plugins.md, kaos-hooks.md |
| 2 | ADRs + Spec | ✅ erledigt | `main` | ADR-0001..0003, spec.md, testing/strategy.md |
| 3 | Testumgebung (Podman + Playwright) | ✅ erledigt | `main` | Container läuft, 8/8 Smoke-Tests grün, MCP-Handshake real verifiziert |
| 4 | Plugin-Kern (Policy, Store, Save-Interception) | ✅ erledigt | `main` | 13/13 Tests grün, alle 3 Pfade (Browser/JSON-RPC/MCP) live verifiziert |
| 5 | Review-UI (Admin-Queue, Diff, Banner) | ✅ erledigt | `main` | 17/17 Tests grün, kompletter Approve/Reject-Loop live verifiziert |
| 6 | 3-Wege-Merge / Konflikte | ✅ erledigt | `main` | Diff3-Automerge + manuelle Auflösung; Kaos-Bug in Diff3 umgangen |
| 7 | Media-Uploads | ✅ erledigt | `main` | Queue + Freigabe, Byte-Integrität getestet |
| 8 | remote.php + MCP-Verifikation | ✅ erledigt | `main` | vorgezogen wegen ADR-0004; 4 MCP-Tools live verifiziert |
| 9 | Härtung, Security-Review, Doku | ⏳ offen | — | |

Details je Phase siehe der freigegebene Plan unter
`/home/martin/.claude/plans/ber-neues-projekt-starten-atomic-feigenbaum.md`.

## Entschiedene Fragen

- Plugin-Basisname: **`reviewqueue`** (2026-08-08 mit dem Nutzer entschieden — fachlich
  präziser als `aireview`, da das Plugin benutzerbasiert und nicht KI-spezifisch ist).
- `martin` bekommt eine eigene `reviewer`-Gruppe statt DokuWiki-Admin-Rechte (sauberere
  Trennung von "darf Reviews machen" und "ist Wiki-Administrator"); wird in Phase 3 so
  geseedet.
