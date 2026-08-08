# Roadmap

Statusanker über Sessions hinweg. Vor Beginn einer neuen Arbeitssitzung hier lesen.

| # | Phase | Status | Branch | Notizen |
|---|---|---|---|---|
| 0 | Repo-Bootstrap | ✅ erledigt | `main` | git init, Grundgerüst, CLAUDE.md |
| 1 | Recherche dokumentieren | ✅ erledigt | `main` | existing-plugins.md, kaos-hooks.md |
| 2 | ADRs + Spec | ✅ erledigt | `main` | ADR-0001..0003, spec.md, testing/strategy.md |
| 3 | Testumgebung (Podman + Playwright) | ⏳ offen | — | |
| 4 | Plugin-Kern (Policy, Store, Save-Interception) | ⏳ offen | — | |
| 5 | Review-UI (Admin-Queue, Diff, Banner) | ⏳ offen | — | |
| 6 | 3-Wege-Merge / Konflikte | ⏳ offen | — | |
| 7 | Media-Uploads | ⏳ offen | — | |
| 8 | remote.php + MCP-Verifikation | ⏳ offen | — | |
| 9 | Härtung, Security-Review, Doku | ⏳ offen | — | |

Details je Phase siehe der freigegebene Plan unter
`/home/martin/.claude/plans/ber-neues-projekt-starten-atomic-feigenbaum.md`.

## Offene Fragen (aus dem Plan übernommen)

- Endgültiger Plugin-Basisname: `aireview` (Arbeitstitel) vs. `reviewqueue`
  (fachlich präziser, da nicht KI-spezifisch). Entscheidung vor Phase 4 nötig.
- `martin` als DokuWiki-Admin oder eigene `reviewer`-Gruppe? Empfehlung: eigene Gruppe,
  im Seeding (Phase 3) entsprechend anlegen.
