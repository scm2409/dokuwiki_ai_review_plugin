# Bestandsaufnahme: existierende DokuWiki-Review-/Approval-Plugins

Recherchestand: 2026-08-08.

## Kurzfassung

DokuWiki hat bewusst **kein** eingebautes Moderations-Workflow — das widerspricht dem
Wiki-Prinzip ("quick" editing). Alle existierenden Lösungen sind Drittanbieter-Plugins,
und alle folgen demselben Grundmuster: **der Save geht sofort durch und erzeugt eine
echte Revision; das Plugin steuert nur, welche Revision welchem Leser angezeigt wird.**
Keines davon hält den Save selbst zurück, und keines ist auf einzelne Benutzer statt auf
Namespaces/Berechtigungen skaliert. Das ist die Lücke, die dieses Projekt schließt.

## `approve` (Szymon Olewniczak, vormals Michael Große)

- Repo: <https://github.com/gkrid/dokuwiki-plugin-approve>, Dokuseite
  <https://www.dokuwiki.org/plugin:approve>. Aktiv gepflegt (`plugin.info.txt` datiert
  2026-02-19, 133 Commits, 23 offene Issues).
- **Modell:** Save erzeugt sofort eine Revision. Über `hide_drafts_for_viewers` sehen
  Nutzer ohne Freigabe-Recht nur die zuletzt *freigegebene* Revision; Editoren sehen
  immer die neueste. Freigabe = Klick auf einen Banner-Link, Berechtigung über
  `strict_approver` + eigenes ACL-Konzept (`helper/acl.php`).
- **Scope:** `apr_namespaces` / `no_apr_namespaces` — Namespace-basiert, nicht
  benutzerbasiert. Alle Autoren in einem betroffenen Namespace werden gleich behandelt.
- **Architektur:** klassisches Plugin-Layout — `action/` (u. a. `approve.php`, `move.php`,
  `notification.php`, `viewmode.php`), `admin.php`, `helper/db.php`
  (`dokuwiki\plugin\sqlite\SQLiteDB` — **Abhängigkeit zum `sqlite`-Plugin**), `remote.php`,
  `syntax/table.php` für eine Übersichtsseite.
- **Hooks** (verifiziert im Quellcode):
  `ACTION_ACT_PREPROCESS` BEFORE (mehrfach — Anzeige-/Approve-/Ready-Handling),
  `COMMON_WIKIPAGE_SAVE` AFTER, `FORM_REVISIONS_OUTPUT` BEFORE,
  `HTML_SHOWREV_OUTPUT` BEFORE, `MENU_ITEMS_ASSEMBLY` AFTER (×2),
  `PARSER_CACHE_USE` BEFORE, `PLUGIN_MOVE_PAGE_RENAME` AFTER,
  `PLUGIN_NOTIFICATION_*` (Benachrichtigungs-Integration),
  `PLUGIN_SQLITE_DATABASE_UPGRADE` AFTER, `TPL_ACT_RENDER` AFTER + BEFORE.
- **Warum es für unseren Fall nicht passt:** Der ungeprüfte Text ist bereits eine echte
  Revision im Live-Verzeichnis, sichtbar für jeden mit Editor-Rechten oder gezieltem
  Revisions-Aufruf. Das ist für "geprüfter Text vs. KI-Entwurf" zu schwach — wir wollen,
  dass der Entwurf *nirgends* als Seiteninhalt existiert, bevor er freigegeben ist. Der
  Scope ist zudem Namespace- statt Benutzer-gebunden, und es kommt eine `sqlite`-Abhängigkeit
  dazu, die wir vermeiden wollen (Entscheidung siehe ADR-0002).
- **Was wir übernehmen:** Plugin-Grundstruktur, Banner-Pattern über `TPL_ACT_RENDER`,
  Menüpunkt-Integration über `MENU_ITEMS_ASSEMBLY`, `remote.php`/`admin.php`-Aufbau.
  Ein Referenz-Klon liegt (nicht Teil des Repos) unter `scratchpad/approve/`.

## `publish` (ursprünglich Jarrod Lowe, jetzt CosmoCode)

- Repo: <https://github.com/cosmocode/dokuwiki-plugin-publish>, Dokuseite
  <https://www.dokuwiki.org/plugin:publish>. 294 Commits, 59 offene Issues, 21 offene PRs.
- **Modell:** identisch zu `approve` im Kern — jede Seite hat eine "published revision",
  die Lesern gezeigt wird, während Editoren beliebig weiterschreiben können. Freigabe
  durch einen Benutzer mit `AUTH_DELETE` oder `AUTH_ADMIN` über einen Banner-Link
  (grün = aktuell freigegeben, rot = ungeprüfte Version).
- **Scope:** berechtigungsbasiert (wer `AUTH_DELETE`/`AUTH_ADMIN` auf der Seite hat, darf
  freigeben) — ebenfalls nicht auf "dieser eine Autor braucht Review" zugeschnitten.
- **Warum es nicht passt:** gleiche strukturelle Einschränkung wie `approve` — Save wird
  sofort zur Revision, kein Zurückhalten des Inhalts.

## `structpublish` (CosmoCode)

- Repo: <https://github.com/cosmocode/dokuwiki-plugin-structpublish>.
- **Modell:** Publish-Workflow aufbauend auf dem `struct`-Plugin, mit einer
  `publish_needs_approve`-Option (vgl. PR #27), die den "Publish"-Button erst nach
  Freigabe freischaltet.
- **Warum es nicht passt:** zusätzliche schwere Abhängigkeit (`struct`), für unseren
  Anwendungsfall (einfacher Seiteninhalt, kein strukturierter Datenteil) unangemessen
  komplex, und wie oben nicht benutzerbasiert.

## Schlussfolgerung

Keines der drei Plugins hält den Save zurück und keines skaliert auf "Review-Pflicht für
bestimmte Benutzer, alle anderen unberührt". Die Entscheidung für eine Neuentwicklung mit
Hold-back-Queue ist in [`docs/design/adr-0001-holdback-vs-hide.md`](../design/adr-0001-holdback-vs-hide.md)
festgehalten.
