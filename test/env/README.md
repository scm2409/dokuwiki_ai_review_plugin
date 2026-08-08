# Testumgebung

DokuWiki **2024-02-06b "Kaos"** in einem Podman-Container, gebaut aus dem offiziellen
Tarball (nicht aus einem Docker-Hub-Image). Nicht für Produktivbetrieb — feste,
schwache Testzugangsdaten.

```bash
test/env/up.sh      # baut/startet den Container, generiert frische API-Tokens
test/env/down.sh     # stoppt und entfernt den Container
```

Nach `up.sh` läuft die Wiki unter `http://localhost:8080/` (Port über
`REVIEWQUEUE_TEST_PORT` änderbar).

## Zugangsdaten

| Login | Passwort | Gruppen | Rolle |
|---|---|---|---|
| `admin` | `admin` | `admin,user` | DokuWiki-Superuser (Wartung, nicht Teil der Testszenarien) |
| `martin` | `martin` | `reviewer,user` | Reviewer, schreibt direkt |
| `kail` | `kail` | `user` | reviewpflichtig über `$conf['plugin']['reviewqueue']['review_users']` (Default in `plugin/conf/default.php`) |

API-Tokens für `martin` und `kail` werden bei jedem `up.sh`-Lauf frisch generiert und
nach `test/e2e/.auth/tokens.json` geschrieben (nicht versioniert).

## Frischer Zustand pro Lauf

`up.sh` entfernt einen eventuell laufenden Container und startet einen neuen — da
`data/` **nicht** als externes Volume gemountet ist, sondern Teil des Image-Layers,
beginnt jeder neue Container automatisch mit dem im Image geseedeten Datenstand
(Copy-on-Write), ohne dass ein manuelles Snapshot/Restore nötig ist.

`plugin/` wird dagegen als Bind-Mount über `lib/plugins/reviewqueue/` gelegt — Änderungen
am Plugin-Code sind sofort im Container sichtbar, ohne Rebuild.

## Was hier lebt

- `Containerfile` — Basis-Image, DokuWiki-Tarball, `mcp`-Plugin (Commit-gepinnt), Seeding
- `seed/conf/` — `local.php`, `acl.auth.php` (Templates, werden ins Image kopiert)
- `seed/gen-users.php` — erzeugt `conf/users.auth.php` mit echten DokuWiki-smd5-Hashes
  (`crypt($clear, '$1$'.$salt.'$')`, siehe `inc/PassHash.php::hash_smd5()`)
- `seed/gen-tokens.php` — erzeugt JWT-API-Tokens über `dokuwiki\JWT::fromUser()`,
  wird per `podman exec` nach dem Start aufgerufen
- `seed/pages/` — vorbefüllte Testseiten (`start`, `playground:test`)

Details zum Testkonzept und der vollständigen Szenarienmatrix:
[`docs/testing/strategy.md`](../../docs/testing/strategy.md).
