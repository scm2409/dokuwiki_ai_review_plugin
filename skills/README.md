# Agent-Skill für den Review-Workflow

[`dokuwiki-reviewqueue/SKILL.md`](dokuwiki-reviewqueue/SKILL.md) erklärt einem
KI-Agenten, wie er mit einem DokuWiki umgeht, dessen Speicherungen über die
Review-Queue laufen. Ohne diesen Kontext läuft ein Agent zuverlässig in zwei
Fallen (siehe [`docs/design/adr-0004-sichtbarkeit-offener-aenderungen.md`](../docs/design/adr-0004-sichtbarkeit-offener-aenderungen.md)):

1. Er hält die `RemoteException` beim Speichern für einen Fehlschlag und meldet
   dem Menschen entweder einen Fehler oder — schlimmer — einen Erfolg, den es
   nicht gab.
2. Er liest die Seite mit `core.getPage` zurück, sieht die Live-Fassung ohne
   seinen Entwurf, hält seine Arbeit für verloren und überschreibt sie beim
   nächsten Speichern selbst.

## Installation

Der Skill ist ein normaler Claude-Skill (Verzeichnis mit `SKILL.md` und
YAML-Frontmatter). Verzeichnis dorthin kopieren oder verlinken, wo der Agent
seine Skills sucht — bei Claude Code z. B.:

```bash
cp -r skills/dokuwiki-reviewqueue ~/.claude/skills/
```

Projektweit statt global: nach `.claude/skills/` im jeweiligen Projekt.

## Voraussetzungen

Der Agent braucht Zugriff auf das DokuWiki-MCP-Plugin
([`splitbrain/dokuwiki-plugin-mcp`](https://github.com/splitbrain/dokuwiki-plugin-mcp))
mit einem API-Token des reviewpflichtigen Kontos. Über dieses Plugin erscheinen
die `plugin_reviewqueue_*`-Werkzeuge automatisch als MCP-Tools — dafür ist auf
der Agentenseite nichts zu konfigurieren.

Token erzeugen: im DokuWiki-Benutzerprofil des Kontos, oder per CLI im
Container (`JWT::fromUser()`, siehe [`test/env/`](../test/env/README.md)).

## Anpassen

Der Skill ist bewusst kontonneutral formuliert — er beschreibt „dein Konto ist
reviewpflichtig", nicht speziell `kail`. Wenn dein Agent zusätzlichen Kontext
braucht (Wiki-URL, Namespace-Konventionen, Tonfall der Inhalte), ergänze das in
einem eigenen Skill oder in der Projekt-`CLAUDE.md`, statt diesen hier
aufzublähen — er soll ausschließlich das Review-Protokoll erklären.
