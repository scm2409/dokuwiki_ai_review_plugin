# Installation

Schritt für Schritt für eine bestehende DokuWiki-Installation, Zielversion
**2024-02-06b "Kaos"**. Für den laufenden Betrieb danach siehe
[`docs/usage.md`](docs/usage.md).

Im Folgenden steht `<dokuwiki>` für das Wurzelverzeichnis deiner Installation
(dort, wo `doku.php` liegt).

## 1. Plugin installieren

Der Verzeichnisname muss exakt `reviewqueue` lauten — DokuWiki leitet daraus die
Klassennamen ab, jeder andere Name führt zu „Plugin installed incorrectly".

```bash
cp -r plugin <dokuwiki>/lib/plugins/reviewqueue
chown -R www-data:www-data <dokuwiki>/lib/plugins/reviewqueue
```

Der Extension-Manager wird **nicht** verwendet — das Plugin liegt nicht auf
dokuwiki.org.

## 2. Gruppe und Konten anlegen

*Administration → Benutzerverwaltung*:

| Konto | Gruppen | Zweck |
|---|---|---|
| dein Konto | `reviewer`, `user` | prüft und gibt frei |
| Agenten-Konto (z. B. `kail`) | `user` | schreibt, aber nur in die Queue |

Die Gruppe `reviewer` existiert in DokuWiki nicht von Haus aus; sie entsteht
einfach dadurch, dass du sie im Benutzer eintragst. Nimm **nicht** die
`admin`-Gruppe: Review und Wiki-Administration sind bewusst getrennt, damit
Prüfen nicht bedeutet, alle Rechte zu haben.

## 3. Konfigurieren

*Administration → Konfiguration*, Abschnitt `reviewqueue` — oder direkt in
`<dokuwiki>/conf/local.php`:

```php
$conf['plugin']['reviewqueue']['review_users']    = 'kail';     // reviewpflichtige Logins
$conf['plugin']['reviewqueue']['review_groups']   = '';         // alternativ ganze Gruppen
$conf['plugin']['reviewqueue']['reviewer_groups'] = 'reviewer'; // wer freigeben darf
```

Solange `review_users` und `review_groups` leer sind, tut das Plugin nichts —
das ist der sichere Ausgangszustand.

## 4. ACL setzen

Das Agenten-Konto braucht **ganz normale Schreibrechte**. Zurückgehalten wird
die Änderung vom Plugin, nicht von der ACL — ohne Schreibrecht käme es gar nicht
erst bis zur Queue.

Empfehlung in *Administration → Zugangsverwaltung*: `Hochladen` (8), nicht
`Löschen` (16). Löschungen laufen zwar ebenfalls über die Queue, aber knappe
Rechte sind die zweite Verteidigungslinie.

## 5. Prüfen, ob es greift

Als Agenten-Konto anmelden, eine Seite bearbeiten und speichern. Erwartet:

> Ihre Änderung an '…' wurde als Änderung #1 zur Prüfung eingereicht. Sie ist
> NOCH NICHT live.

Als Reviewer anmelden → *Site Tools → Review Queue*. Dort muss die Änderung mit
Diff stehen. Erscheint der Menüpunkt nicht, stimmt die Gruppe aus
`reviewer_groups` nicht mit der Gruppe deines Kontos überein.

Mit einem dritten, normalen Konto testen, dass dort alles unverändert
funktioniert.

---

# Anbindung eines KI-Agenten über MCP

Nur nötig, wenn der Agent das Wiki selbst bedienen soll.

## 6. MCP-Plugin installieren

```bash
cd <dokuwiki>/lib/plugins
git clone https://github.com/splitbrain/dokuwiki-plugin-mcp.git mcp
chown -R www-data:www-data mcp
```

Für reproduzierbare Installationen auf einen Commit festnageln (die
Testumgebung dieses Projekts nutzt `c44faefa170c63435ccd19c3a25e84e2e2a24c53`):

```bash
git -C mcp checkout c44faefa170c63435ccd19c3a25e84e2e2a24c53
```

## 7. Remote-API einschalten

*Administration → Konfiguration*:

- `remote` → **aktivieren**
- `remoteuser` → auf die Konten begrenzen, die die API wirklich brauchen, z. B.
  `kail,dein-konto`. Leer lassen heißt **alle** Benutzer — dann kann jedes
  Fremdplugin mit eigener schreibender API-Methode von jedem Konto aus
  aufgerufen werden.

## 8. API-Token holen

Als das jeweilige Konto anmelden → *Benutzerprofil* (`?do=profile`). Unten steht
ein API-Token als langer `eyJ…`-String. Kopieren.

Das Token ist ein Passwortäquivalent für dieses Konto. Über die Schaltfläche im
Profil lässt es sich neu erzeugen, wodurch das alte sofort ungültig wird.

## 9. MCP-Client konfigurieren

Endpunkt ist `https://<dein-wiki>/lib/plugins/mcp/mcp.php` über HTTP-Transport,
Authentifizierung per Header:

```
Authorization: Bearer <token>
```

Für Claude Code beispielsweise:

```bash
claude mcp add --transport http dokuwiki https://<dein-wiki>/lib/plugins/mcp/mcp.php --header "Authorization: Bearer <token>"
```

Die genaue Syntax hängt vom Client ab — entscheidend sind nur URL, HTTP-Transport
und der Bearer-Header.

Schnelltest ohne Client:

```bash
curl -sS -X POST https://<dein-wiki>/lib/plugins/mcp/mcp.php \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}'
```

Die Antwort muss `"You are authenticated as '<konto>'"` enthalten. Steht dort
stattdessen, dass kein Token akzeptiert wurde, stimmt der Header oder
`remoteuser` nicht.

## 10. Skill für den Agenten installieren

Ohne diesen Kontext missversteht ein Agent den Ablauf zuverlässig: Er hält die
Fehlermeldung beim Speichern für einen Fehlschlag, oder er liest die Seite mit
`core.getPage` zurück, sieht seinen Entwurf nicht und überschreibt beim nächsten
Speichern seine eigene ungeprüfte Arbeit.

```bash
cp -r skills/dokuwiki-reviewqueue ~/.claude/skills/
```

Projektbezogen statt global: nach `.claude/skills/` im jeweiligen Projekt.
Details in [`skills/README.md`](skills/README.md).

## 11. Ende-zu-Ende prüfen

Den Agenten eine Seite ändern lassen. Erwartet: Er meldet, die Änderung sei zur
Prüfung eingereicht — **nicht**, die Seite sei aktualisiert. Die Änderung liegt
in der Review Queue, die Seite ist unverändert.

---

## Aktualisieren

```bash
rsync -a --delete plugin/ <dokuwiki>/lib/plugins/reviewqueue/
```

`--delete` ist wichtig, damit entfernte Dateien nicht zurückbleiben. Die Queue
liegt unter `<dokuwiki>/data/reviewqueue/` und wird davon nicht berührt.

## Deinstallieren

**Vorher die Queue leeren** — offene Änderungen werden sonst nie veröffentlicht:

```bash
php <dokuwiki>/bin/plugin.php reviewqueue list
```

Danach:

```bash
rm -rf <dokuwiki>/lib/plugins/reviewqueue
```

Die `reviewqueue`-Einträge in `conf/local.php` können stehen bleiben, sie werden
ohne das Plugin ignoriert. `data/reviewqueue/` aufheben, falls dort noch etwas
liegt — es sind reine Textdateien, die sich von Hand übertragen lassen.

## Wenn etwas nicht funktioniert

| Symptom | Ursache |
|---|---|
| „Plugin installed incorrectly. Rename plugin directory" | Verzeichnis heißt nicht `reviewqueue` |
| Speichern geht direkt live | Konto steht nicht in `review_users`/`review_groups`; Schreibweise und Groß-/Kleinschreibung prüfen |
| Kein Menüpunkt *Review Queue* | Konto ist nicht in einer Gruppe aus `reviewer_groups` |
| „The review queue could not be written to" | `data/reviewqueue/` nicht durch den Webserver beschreibbar — bewusst so: im Zweifel wird abgelehnt statt ungeprüft veröffentlicht |
| MCP-Aufrufe scheitern mit „not authorized" | `remote` aus, oder Konto nicht in `remoteuser` |
| Agent meldet „Seite gespeichert", obwohl sie es nicht ist | Skill aus Schritt 10 fehlt |
