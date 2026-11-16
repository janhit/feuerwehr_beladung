# Feuerwehr Beladung

WordPress-Plugin für Freiwillige Feuerwehren: Mitglieder lernen zu Hause oder unterwegs
am Handy, welches Gerät in welchem Gerätefach der Fahrzeuge liegt.

> **Stand:** in Entwicklung. Version 0.1 enthält das Backend (Fahrzeuge, Gerätekatalog,
> Editor, Beispielfahrzeug). Die Lernseite für Mitglieder folgt.

## Was das Plugin kann (Version 0.1)

- **Fahrzeuge** anlegen, als Entwurf vorbereiten und veröffentlichen.
- **Steckbrief** je Fahrzeug: Foto, Kurzbeschreibung, frei benennbare Felder (bis zu drei als Eckdaten).
- **Außenansichten** (Fahrerseite, Beifahrerseite, Heck, Dach …): Foto wählen, Gerätefächer mit der Maus aufziehen.
- **Fächer** mit beliebig vielen Bildern, z. B. „Schwenkwand geschlossen“ und „Schwenkwand geöffnet“. Schalter für Schwenkwand (gebogener Pfeil) und Auszug (gerader Pfeil) verbinden die Bilder.
- **Gerätekatalog** für die ganze Seite: Jedes Gerät wird einmal beschrieben und dann in beliebig vielen Fächern markiert.
- **Protokoll**: wer hat wann was geändert. **Frühere Stände** eines Fahrzeugs lassen sich wiederherstellen.
- **Beispielfahrzeug** per Knopf, auch ohne Fotos.

## Rechte

- Bearbeiten: Redakteure und Administratoren.
- Einstellungen: nur Administratoren.
- Mitglieder (alle angemeldeten Nutzer) sehen später die Lernseite.

## Installation

1. Unter **Releases** die Datei `feuerwehr-beladung.zip` herunterladen (sobald es Releases gibt).
2. In WordPress: **Plugins → Installieren → Plugin hochladen**.
3. Aktivieren. Im Menü erscheint **Beladung**.

## Updates

Neue Versionen erscheinen als Release auf GitHub. WordPress zeigt sie wie jedes andere
Plugin-Update unter **Plugins** an („Jetzt aktualisieren“). Mit **Nach Updates suchen**
(in der Plugin-Liste oder unter **Beladung → Einstellungen**) lässt sich sofort nachsehen.

Daten (Fahrzeuge, Fotos, Lernstände) bleiben bei Updates immer erhalten: Ein Update ersetzt
nur den Programmcode im Plugin-Ordner.

Für die Update-Prüfung fragt der Server (nicht der Browser der Mitglieder) etwa alle
6 Stunden bei `api.github.com` nach der neuesten Version. Dabei werden keine Nutzerdaten
übertragen.

## Entwicklung

Kein Build-Schritt: PHP, JavaScript-Module und CSS liegen so im Repository, wie sie ausgeliefert werden.
Details zu Aufbau, Regeln und Tests stehen in [CLAUDE.md](CLAUDE.md).

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
