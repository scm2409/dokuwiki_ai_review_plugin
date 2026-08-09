# Audit: Kann ein reviewpflichtiges Konto etwas direkt ändern?

Anlass: die Frage, ob `kail` Änderungen am Wiki vornehmen kann, die kein Review
durchlaufen — mit `move` (Seiten umbenennen) als genanntem Beispiel für eine
Operation, die sich nicht sinnvoll reviewen lässt und die das Konto deshalb gar
nicht erst ausführen können soll.

Vorgehen: **jede** Methode der Remote-API durchgegangen (nicht nur die als MCP-Tool
sichtbaren), dazu die Browser-Aktionen. Ergebnisse gegen den laufenden Container
verifiziert, nicht aus dem Quelltext geschlossen.

## Ergebnis je Schreibpfad

| Pfad | Vorher | Jetzt |
|---|---|---|
| `core.savePage`, `core.appendPage` | Queue | Queue |
| `core.saveMedia` | Queue | Queue |
| **`core.deleteMedia`** | ⚠️ **ging direkt durch** | Queue |
| `core.lockPages` / `unlockPages` | nur Sperren, kein Inhalt | unverändert |
| `core.login` / `logoff` | nur eigene Sitzung | unverändert |
| `plugin.acl.addAcl` / `delAcl` / `listAcls` | vom ACL-Plugin auf Admins beschränkt | unverändert, jetzt getestet |
| `plugin.usermanager.createUser` / `deleteUser` | auf Admins beschränkt | unverändert, jetzt getestet |
| `dokuwiki.createUser` / `deleteUsers` (Legacy) | `auth_isadmin()` | unverändert, jetzt getestet |
| Browser-Aktionen von Fremdplugins (z. B. `move`) | ⚠️ **liefen ungehindert** | per Allowlist verweigert |

## Die beiden geschlossenen Lücken

### `core.deleteMedia`

Medien-Löschungen laufen über `media_delete()`, nicht über
`MEDIA_UPLOAD_FINISH` — der Upload-Hook griff hier also nicht. Ein
reviewpflichtiges Konto konnte damit Dateien **löschen**, obwohl es keine
hinzufügen konnte. Nötig ist dafür lediglich `AUTH_DELETE`, das in einer
üblichen ACL schnell vergeben ist.

Jetzt über `MEDIA_DELETE_FILE` (preventable, `inc/media.php:276`) abgefangen und
als Änderung vom Typ `media` mit `operation = delete` eingereiht. Die Freigabe
führt die Löschung als ursprünglicher Antragsteller aus. Das Review-UI weist
deutlich darauf hin, dass eine Freigabe die Datei entfernt.

Bewusst gequeued statt blockiert: Löschen ist eine reviewbare Absicht, genau wie
das Löschen einer Seite (leerer Text), das schon immer durch die Queue lief.

### Aktionen von Fremdplugins

Plugins bringen eigene Aktionen mit (`do=…`), die das Wiki verändern, ohne
`COMMON_WIKIPAGE_SAVE` zu berühren — beim `move`-Plugin etwa das Umbenennen von
Seiten. Für die Queue gibt es dabei nichts abzufangen.

Statt einzelne bekannte Plugins zu blockieren, gilt für reviewpflichtige Konten
jetzt eine **Allowlist erlaubter Aktionen** (`action/save.php`): Lesen,
Navigieren, Bearbeiten und Speichern sind erlaubt, alles andere wird mit
Hinweis abgewiesen. Damit ist ein erst später installiertes Plugin
standardmäßig gesperrt statt unbemerkt ungeprüft — das ist die sichere
Fehlerrichtung. Wird eine zusätzliche Aktion gebraucht, muss sie bewusst in die
Liste aufgenommen werden.

## Was ausdrücklich **nicht** abgedeckt ist

**Remote-API-Methoden von Fremdplugins.** Kaos bietet keinen Hook, mit dem sich
ein Remote-Aufruf abfangen ließe (kein `RPC_CALL`-Event; `Api::call()` ruft die
Methode direkt auf). Ein installiertes Plugin, das eine schreibende
`RemotePlugin`-Methode mitbringt und diese *nicht* selbst auf Admin-Rechte
prüft, wäre für ein reviewpflichtiges Konto erreichbar.

Der Test `lockdown.api.spec.ts` fängt genau das ab: Er vergleicht die Liste
aller nicht als read-only markierten Remote-Methoden gegen eine Aufstellung, in
der zu jeder Methode steht, *warum* sie unbedenklich ist. Taucht eine unbekannte
schreibende Methode auf — durch ein DokuWiki-Update oder ein neu installiertes
Plugin — schlägt der Test fehl, statt dass die Lücke unbemerkt bleibt.

Zusätzliche Absicherung im Betrieb, siehe [`../usage.md`](../usage.md):

- ACL für das reviewpflichtige Konto so eng wie möglich (kein `AUTH_DELETE`,
  wenn keine Löschungen nötig sind),
- `$conf['remoteuser']` auf die Konten begrenzen, die die API wirklich brauchen,
- schreibende Plugins mit eigener Remote-API zurückhaltend installieren.
