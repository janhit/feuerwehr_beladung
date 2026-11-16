# CLAUDE.md – Feuerwehr Beladung

WordPress-Plugin, mit dem Mitglieder einer Freiwilligen Feuerwehr am Handy lernen,
welches Gerät in welchem Gerätefach der Fahrzeuge liegt. Repository:
https://github.com/janhit/feuerwehr_beladung (öffentlich).

## Zusammenarbeit

- Antworten auf Deutsch, in einfacher Sprache. Der Auftraggeber ist kein WordPress-Entwickler.
- Gebaut wird in Stufen. Jede Stufe auf einem eigenen Branch mit Pull Request; der Auftraggeber testet, bevor es weitergeht.
- **Vor jeder Veröffentlichung (neue Versionsnummer auf main / Release) fragen.**
- Ehrlich sagen, was nicht getestet werden konnte.

## Feste Regeln

- **Kein Build-Schritt.** Reines PHP, JavaScript (ES-Module) und CSS. Das Release-ZIP enthält den Code so, wie er im Repository liegt.
- **Daten nie im Repository.** Beladung, Fotos und Lernstände liegen in Datenbank und Mediathek. Updates dürfen sie nicht verändern; `uninstall.php` löscht nur, wenn ein Admin das ausdrücklich eingestellt hat.
- **Nur angemeldete Nutzer** sehen die Lernseite und ihre Daten. Beitragstypen sind `public => false`, `show_in_rest => false`. Eigene REST-Routen unter `fwb/v1` prüfen die Rechte immer im `permission_callback`.
- **Keine externen Schriften, CDNs oder Dienste.**
- **Alle Texte deutsch.** Code-Bezeichner ebenfalls deutsch (Umlaute als ae/oe/ue).
- **Jede Eingabe wird auf dem Server Feld für Feld bereinigt** (`includes/class-schema.php`). Neue Felder immer dort ergänzen und testen.
- Positionen auf Fotos immer **in Prozent** (0–100), nie in Pixeln.
- Mindestens **PHP 8.1** und **WordPress 6.5** (Script-Module).

## Rechte

| Recht | Wer | Wofür |
|---|---|---|
| angemeldet (`Plugin::darf_lernen`, Filter `fwb_darf_lernen`) | alle Mitglieder | Lernseite |
| `fwb_bearbeiten` | Redakteur, Administrator | Fahrzeuge, Gerätekatalog, Protokoll, Beispielfahrzeug |
| `fwb_verwalten` | Administrator | Einstellungen, Update, Lernstände löschen |

## Datenmodell

- **Fahrzeug** = Beitragstyp `fwb_fahrzeug` (Entwurf/Veröffentlicht von WordPress). Die Beladung steht als **JSON** im Meta-Feld `_fwb_beladung`. Die letzten 10 Stände stehen in `_fwb_versionen`.
- **Gerätekatalog** = Beitragstyp `fwb_geraet`, ein Eintrag pro Gerät für die ganze Seite. Feste Kennung in `_fwb_uid` (`g_…`), dazu `_fwb_beschreibung`, `_fwb_hinweise`, `_fwb_suchbegriffe`. Ein Gerät, das noch in einem Fahrzeug liegt, lässt sich nicht löschen.
- **Protokoll** = Tabelle `{prefix}fwb_protokoll` (höchstens 2000 Zeilen).

Aufbau des JSON (Schema 1):

```
steckbrief: { foto_id, kurz, felder: [ {label, wert, eckdatum} ] }      // höchstens 3 Eckdaten
ansichten:  [ {id, name, foto_id, skizze, bereiche: [ {id, fach_id, x, y, b, h} ]} ]
faecher:    [ {id, kuerzel, name,
               plaetze: [ {id, geraet (uid), menge, lage, notiz} ],
               bilder:  [ {id, titel, foto_id, skizze,
                           markierungen: [ {id, platz_id, x, y, b, h, notiz} ],   // mehrere je Gerät erlaubt
                           schalter:     [ {id, art: schwenk|auszug, ziel_bild_id, x, y} ]} ]} ]
```

- Alle IDs sind fest und zufällig (`f_…`, `b_…`, `p_…` usw.). Lernstand, Export, QR-Codes und „Neu seit …“ hängen an diesen IDs.
- Ein Fach hat mindestens ein Bild. Schalter zeigen auf ein **anderes** Bild desselben Fachs. Ob der Pfeil „öffnen“ oder „schließen“ zeigt, ergibt sich aus der Reihenfolge der Bilder.
- Ein neues Bild übernimmt die Markierungen des aktuellen Bildes (nicht die Schalter).
- Dasselbe Gerät darf in mehreren Fächern liegen, aber nur einmal pro Fach (ein Platz). Ein Platz darf auf einem Bild **mehrere Markierungen** haben (gleiches Gerät an mehreren Stellen), jede mit eigener `notiz`.
- Fahrzeuge ohne Fotos nutzen Skizzen aus `assets/skizzen/` (Liste in `Schema::SKIZZEN`).

## Dateien

```
feuerwehr-beladung.php        Plugin-Kopf (Version, Update URI), Autoloader
uninstall.php                 löscht Daten nur auf ausdrücklichen Wunsch
includes/
  class-plugin.php            Start, Hooks, Rechte-Hilfen, datei_version()
  class-installation.php      Aktivierung, DB-Stand (läuft auch nach Updates)
  class-rechte.php            Rechte und Rollen
  class-schema.php            Datenmodell + Bereinigung (Herzstück)
  class-beladung.php          Lesen/Speichern/Versionen
  class-fahrzeug-typ.php      Beitragstyp Fahrzeug, Listenspalten
  class-geraete-katalog.php   Beitragstyp Gerät, Suche, Löschschutz
  class-editor.php            Metabox + Speichern + Skripte
  class-rest.php              REST fwb/v1 (bisher: Gerätekatalog)
  class-protokoll.php         Protokoll-Tabelle und -Seite
  class-beispiel.php          Beispielfahrzeug
  class-einstellungen.php     Einstellungen (Admin)
  class-admin-menue.php       Untermenüs
admin/js/editor/              ES-Module: main (Oberfläche), modell (reine Logik: Rechtecke, Übertragen, offenePunkte, Verlauf), markierer, katalog
admin/css/editor.css
assets/skizzen/               SVG-Skizzen für Fahrzeuge ohne Fotos
tests/php/                    PHPUnit gegen echtes WordPress (eigene Test-Datenbank!)
tests/js/                     node --test für admin/js/editor/modell.js
bin/tests-auf-server.sh       Tests auf dem Test-Server
.github/workflows/tests.yml   Tests bei jedem Push
```

JS-Module werden über `wp_register_script_module` mit Abhängigkeiten registriert
(Import-Map, Namen `@fwb/editor/…`). Neue Module in `Editor::MODULE` eintragen.

## Testen

- **Testseite:** https://fwb-test.hittig.tech (Docker auf dem VPS des Auftraggebers, `/opt/fwb-test`, nginx + PHP-FPM wie die Vereinsseite). Testnutzer `fwb-admin`, `fwb-redakteur`, `fwb-mitglied`; Zugangsdaten nur auf dem Server in `/opt/fwb-test/zugangsdaten.txt`. Den Docker-Stack `oc-personen` auf dem VPS nie anfassen.
- **Automatische Tests auf dem Server:** Plugin nach `/opt/fwb-test/plugin` kopieren, dann `sh /opt/fwb-test/plugin/bin/tests-auf-server.sh` (eigene Datenbank `wordpress_tests`).
- **Lokal:** `node --test 'tests/js/*.test.js'`; PHPUnit mit `WP_ROOT=/pfad/zu/wordpress vendor/bin/phpunit` (nur gegen eine Test-Datenbank).
- Im Testbetrieb (`WP_DEBUG`) hängt `Plugin::datei_version()` das Änderungsdatum an CSS/JS, damit Browser nichts Altes zeigen.
- Bei jeder Stufe im Browser prüfen: Admin, Redakteur, Mitglied, abgemeldet; Handy (375 px) und Rechner.

## Stufen

| Stufe | Inhalt | Stand |
|---|---|---|
| 1 | Grundgerüst, Datenmodell, Backend-Editor, Gerätekatalog, Protokoll, Beispielfahrzeug | in Arbeit (Branch `stufe-1-grundgeruest`) |
| 4 | Update aus GitHub (Update URI + Filter `update_plugins_github.com`), GitHub-Action baut ZIP mit Ordner `feuerwehr-beladung/` | als Nächstes |
| 2 | Lernseite (Shortcode, vier Ebenen, Schwenkwand/Auszug, Suche, Steckbrief), Fotoschutz | offen |
| 3 | Quiz, Lernstand (Opt-in, pseudonymisiert + verschlüsselt), Datenschutz-Werkzeuge | offen |
| 5 | Export/Import eines Fahrzeugs samt Fotos und Katalog-Geräten | offen |

## Editor (Bedienung)

- Leiste oben (sticky): Status, Rückgängig/Wiederholen (`M.neuerVerlauf`, ganze JSON-Stände, Tippen wird zusammengefasst), „Speichern“ löst WordPress' `#save-post` (Entwurf) bzw. `#publish` aus.
- „Was fehlt noch?“ = `M.offenePunkte(dok)`; jeder Punkt hat ein Sprungziel. Neue Prüfungen dort ergänzen und in `tests/js` testen.
- Fach-Reiter: Foto links, Geräte rechts (Container-Query ab 760 px), sonst untereinander. Geräteliste behält ihre Scrollposition über `zeichnen()`.
- `zeichnen()` baut alles neu; bei Eingabefeldern `gemerkt( false, gruppe )` nutzen, damit der Fokus bleibt.

## Entscheidungen (mit dem Auftraggeber abgestimmt)

- Gerätekatalog für die ganze Seite; Menge und Lage stehen je Fach.
- Quiz: Mitglied tippt ein oder mehrere Fächer an; fehlende Fächer werden in der Lösung gezeigt; die Anzahl wird vorher nicht verraten.
- Dach ist eine normale Ansicht (Foto von oben).
- Fotoschutz: eigener Ordner, zufällige Dateinamen, Auslieferung über das Plugin mit Login-Prüfung, Selbsttest in den Einstellungen. Die Vereinsseite läuft hinter openresty (nginx) → `.htaccess` greift dort vermutlich nicht.
- Lernstand: Pseudonym (HMAC) + Verschlüsselung (libsodium), Schlüssel aus `wp-config.php`, nicht aus der Datenbank.
- 10 Fragen pro Quizrunde.

## Offene Punkte

- Lernseite: Fotos in hoher Auflösung ausliefern (der Editor nutzt nur „large“ = 1024 px).
- Hinweis „vorübergehend geändert“ an einem Gerät (Wunsch aus dem Vereinsalltag), passt zu „Neu seit …“.
- Protokoll enthält Namen von Bearbeitern → in Stufe 3 bei den Datenschutz-Werkzeugen berücksichtigen.
- Später: Export/Import (zuerst), PWA mit eigener Ansicht ohne Theme, weitere Quizarten, „Neu seit …“, QR-Codes.
- Nicht geplant: Prüffristen und Gerätewartung.
