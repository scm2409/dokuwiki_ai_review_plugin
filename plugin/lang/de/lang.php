<?php

$lang['queued']         = "Ihre Änderung an '%s' wurde als Änderung #%d zur Prüfung eingereicht. Sie ist NOCH NICHT live.";
$lang['queued_stacked'] = 'Achtung: Für diese Seite liegen bereits ungeprüfte Änderungen %s von Ihnen vor. Diese neue Änderung basiert auf der Live-Fassung, nicht auf jenen — werden alle freigegeben, überschreibt die neueste die frühere Arbeit.';
$lang['queue_failed']   = 'Die Review-Warteschlange konnte nicht geschrieben werden, Ihre Änderung wurde nicht gespeichert. Bitte versuchen Sie es erneut oder wenden Sie sich an eine Administratorin/einen Administrator.';

$lang['menu']  = 'Review-Warteschlange';
$lang['empty'] = 'Es liegen keine offenen Änderungen vor.';
$lang['meta']  = 'Von %s: „%s“ (%s)';

$lang['comment_label'] = 'Kommentar (wird dem Autor/der Autorin angezeigt, bei Ablehnung erforderlich)';
$lang['btn_approve']   = 'Freigeben';
$lang['btn_reject']    = 'Ablehnen';

$lang['approved']         = 'Änderung #%d freigegeben und veröffentlicht.';
$lang['rejected']         = 'Änderung #%d abgelehnt.';
$lang['conflicted']       = 'Änderung #%d konnte nicht freigegeben werden: Die Seite hat sich seit der Einreichung geändert.';
$lang['conflict_notice']  = 'Die Live-Seite hat sich seit der Einreichung geändert. Eine automatische Freigabe ist nicht möglich.';
$lang['apply_failed']     = 'Beim Anwenden Ihrer Entscheidung ist etwas schiefgelaufen. Bitte versuchen Sie es erneut oder wenden Sie sich an eine Administratorin/einen Administrator.';
$lang['not_found']        = 'Diese offene Änderung existiert nicht mehr oder wurde bereits entschieden.';
$lang['no_self_review']   = 'Sie können Ihre eigene Änderung nicht freigeben oder ablehnen.';
$lang['approved_summary'] = '(freigegeben von %s, Änderung #%d)';

$lang['banner']      = 'Für diese Seite liegen %d offene Änderung(en) zur Prüfung vor.';
$lang['banner_link'] = 'Review-Warteschlange öffnen';

$lang['stacked_notice'] = 'Vorsicht: Für diese Seite liegen %d ungeprüfte Änderungen vor (#%s), die jeweils auf der Live-Fassung basieren und nicht aufeinander. Werden mehrere freigegeben, überschreiben die späteren die früheren. Bitte einzeln prüfen und die nicht gewünschten ablehnen.';

$lang['resolved']      = 'Änderung #%d aufgelöst und veröffentlicht.';
$lang['resolve_label'] = 'Aufgelöster Seitentext (Konfliktmarker entfernen und stehen lassen, was auf der Seite stehen soll)';
$lang['btn_resolve']   = 'Aufgelösten Text veröffentlichen';
$lang['markers_left']  = 'Der Text enthält noch Konfliktmarker. Bitte entfernen und nur die gewünschte Fassung stehen lassen.';
$lang['no_base']       = 'Die Fassung, gegen die diese Änderung geschrieben wurde, ist nicht mehr verfügbar — ein automatischer Merge ist daher nicht möglich. Unten steht der vorgeschlagene Text für sich; bitte selbst mit der aktuellen Seite vergleichen.';

$lang['diff_label']     = 'Diff';
$lang['preview_label']  = 'Vorschau';
$lang['preview_delete'] = 'Diese Änderung schlägt vor, die Seite zu LÖSCHEN. Eine Freigabe entfernt sie aus dem Wiki.';

$lang['media_info']      = 'Hochgeladene Datei: %s, %s.';
$lang['media_overwrite'] = 'Dieser Upload würde eine bereits vorhandene Datei ersetzen.';

$lang['media_delete'] = 'Diese Änderung schlägt vor, die Datei zu LÖSCHEN. Eine Freigabe entfernt sie aus dem Wiki.';
$lang['act_denied']   = 'Die Aktion „%s" steht Ihrem Konto nicht zur Verfügung, weil so vorgenommene Änderungen kein Review durchlaufen können.';
