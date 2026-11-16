# Änderungen

## 0.1.0 – noch nicht veröffentlicht

Stufe 1: Grundgerüst und Backend.

- Fahrzeuge als eigener, nicht öffentlicher Beitragstyp (Entwurf/Veröffentlicht).
- Beladung als strukturiertes Dokument mit Bereinigung jedes Feldes auf dem Server.
- Editor mit drei Reitern: Steckbrief, Außenansichten, Fächer und Geräte.
- Markierungen mit der Maus aufziehen, verschieben und in der Größe ändern; Pfeiltasten und Entf.
- Mehrere Bilder je Fach; neues Bild übernimmt die Markierungen; Schalter für Schwenkwand und Auszug.
- Gerätekatalog für die ganze Seite mit Suche, Neuanlage im Editor und Löschschutz.
- Protokoll und die letzten 10 Stände je Fahrzeug.
- Beispielfahrzeug „HLF Beispiel“ mit Skizzen statt Fotos.
- Rechte: Redakteure bearbeiten, Administratoren verwalten.

Editor übersichtlicher (nach erstem Test):

- Foto und Geräteliste nebeneinander; beim Markieren rückt das Foto automatisch in den Blick.
- Gerät in der Liste und seine Rechtecke auf dem Foto heben sich gegenseitig hervor.
- Ein Gerät kann an mehreren Stellen eines Bildes markiert werden („+ Weitere Stelle markieren“), jede Stelle mit eigener Notiz (z. B. „oben links“).
- Markierung auf andere Bilder übertragen; bei einem neuen Gerät fragt der Editor, ob es auf den anderen Bildern an derselben Stelle liegt.
- „Was fehlt noch?“ listet offene Punkte (unmarkierte Geräte, fehlende Fotos, Bilder ohne Schalter, Fächer ohne Außenansicht) mit Sprung dorthin.
- Leiste oben mit Speichern, Rückgängig und Wiederholen (auch Strg+Z / Strg+Umschalt+Z).
- Reiter in Arbeitsreihenfolge: Steckbrief, Fächer und Geräte, Außenansichten. Fächer und Ansichten als Knöpfe nebeneinander.
- Leitfaden „So geht es los“ für ein leeres Fahrzeug.
- Kasten „Veröffentlichen“: missverständliche Zeile „Sichtbarkeit: Öffentlich“ ersetzt durch einen klaren Hinweis.
- Pfeiltasten verschieben Markierungen jetzt auch sichtbar.

Stufe 4: Update aus GitHub.

- Neue Versionen aus den GitHub-Releases erscheinen unter „Plugins“ mit dem Knopf „Jetzt aktualisieren“.
- „Nach Updates suchen“ in der Plugin-Liste und in den Einstellungen (nur Administratoren).
- Release-Paket wird automatisch gebaut und geprüft, sobald eine Version freigegeben ist.
