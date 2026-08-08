# Code-Review der Implementierung (2026-08-08)

Durchsicht des Plugin-Quellcodes, wie vom Nutzer angefordert. Getrennt nach
behoben / bewusst offen, damit der Stand ehrlich nachvollziehbar bleibt.

## Behoben

### 1. Verwaister Seiten-Lock nach abgefangenem Remote-Save (schwerwiegend)

`ApiCore::savePage()` ist aufgebaut als `lock()` → `saveWikiText()` → `unlock()`.
Die `RemoteException` aus [ADR-0003](adr-0003-ki-feedback-remoteexception.md) fliegt
mitten aus `saveWikiText()` heraus, also wurde `unlock()` **nie** erreicht.

Wirkung: Nach jeder eingereichten Änderung blieb die Seite für die volle
Lock-Dauer gesperrt. `martin` bekam „The page is currently locked" und konnte
genau die Seiten nicht bearbeiten, an denen der Agent arbeitet — also exakt die
Störung des normalen Wiki-Betriebs, die dieses Plugin vermeiden soll. Ein
dauerhaft schreibender Agent hätte Seiten praktisch dauerbelegt.

Behoben in `action/save.php`: expliziter `unlock()` vor dem Werfen. Regressionstest
in `visibility.api.spec.ts`. Der Browser-Pfad war nie betroffen, weil er normal
zurückkehrt und `dokuwiki\Action\Save` sein eigenes `unlock()` erreicht.

### 2. Mehrzeilige Docblock-Tags zerstören die MCP-Tool-Beschreibungen

DokuWikis Parser (`inc/Remote/OpenApiDoc/DocBlock.php:49`) entfernt bei
`@param`/`@return`/`@throws` nur die *erste* Zeile. Fortsetzungszeilen landeten in
der generierten Tool-Beschreibung, sodass Agenten Fragmente wie
„'approved', 'rejected' or 'superseded'), comment (reviewer's" als Tool-Doku sahen.
Behoben: Tags einzeilig, Strukturbeschreibung im Prosateil. Test in
`hardening.api.spec.ts` prüft die Beschreibungen jetzt mit.

### 3. `$conf['savedir']` als Pfadanker (bereits in Phase 4 behoben, hier dokumentiert)

Siehe `CLAUDE.md`. Relativer Config-Wert, der je nach Einstiegsskript anders
auflöst — jetzt `dirname($conf['datadir'])`.

### 4. ID-Kollision beim Einreihen

`io_lock()` gibt nach 3 Sekunden auf und behandelt den Lock als veraltet; unter
pathologischer Last könnten zwei Aufrufer dieselbe ID bekommen und der zweite
den ersten überschreiben. Ein Lasttest mit 8 parallelen Saves ergab saubere,
eindeutige IDs — das Risiko ist also gering, aber stiller Verlust einer
eingereichten Änderung widerspricht dem fail-closed-Prinzip. `enqueue()` bricht
jetzt ab, statt zu überschreiben.

## Bewusst offen

### A. Reviewer-Zugriff prüft die ACL der Zielseite nicht

`remote.php::checkChangeAccess()` und die Admin-Queue lassen jeden Nutzer aus
`reviewer_groups` **jede** offene Änderung einsehen — auch wenn er die Zielseite
selbst nicht lesen dürfte. In der Testumgebung fällt das nicht auf (alle dürfen
alles).

Korrekt wäre zusätzlich `auth_quickaclcheck($target) >= AUTH_READ`. Nicht sofort
geändert, weil es den Review-Prozess in Installationen mit engen ACLs
stillschweigend brechen kann (Änderungen würden für den zuständigen Reviewer
unsichtbar in der Queue liegen bleiben) — das braucht eine bewusste Entscheidung
darüber, was dann passieren soll, plus eine restriktive Test-ACL. Gehört in
Phase 9 (Security-Review).

### B. `archive()` kann teilweise ausgeführt abbrechen

Verschiebt `.json` und `.content` nacheinander. Schlägt das zweite `rename()`
fehl, bleibt ein halb archivierter Zustand zurück. Praktisch unwahrscheinlich
(gleiches Dateisystem, unmittelbar nacheinander); der Effekt wäre eine Änderung,
deren Metadaten archiviert sind, deren Inhalt aber noch in `queue/` liegt.

### C. `replaySave()` setzt nur `REMOTE_USER`, nicht `$USERINFO`

Für die Changelog-Attribution und Benachrichtigungen reicht das (verifiziert:
Freigaben erscheinen korrekt als `kail`). Ein Fremdplugin, das in
`COMMON_WIKIPAGE_SAVE` auf `$USERINFO['grps']` schaut, sähe während der Freigabe
allerdings die Gruppen des Reviewers. Bisher kein bekannter Fall.

### D. `getContent()` liest über `io_readFile()` mit `cleanText()`

Für Wikitext richtig (DokuWiki behandelt Seiten genauso). **Für Phase 7 (Media)
ist es eine Falle**: Binärdaten dürfen so nicht gelesen werden. Beim Umsetzen der
Media-Queue muss der Binärpfad `file_get_contents()`/`file_put_contents()`
verwenden.

## Nicht beanstandet

- Kein Path-Traversal-Risiko bei Änderungs-IDs: alle Eingänge casten nach `int`
  (`$INPUT->int('rqid')`, `(int) $id`).
- Ausgabe-Escaping in `admin.php`/`action/banner.php` durchgehend über `hsc()`;
  der Diff wird vom Core-Formatter escaped.
- CSRF über `checkSecurityToken()` in `admin.php::handle()`, mit Test.
- Selbst-Freigabe-Verbot greift auch bei direktem POST mit gültigem Token, mit Test.
- Re-Entrancy-Flag wird in `finally` zurückgesetzt, übersteht also Exceptions.
