#!/bin/sh
# Baut dist/feuerwehr-beladung.zip aus dem aktuellen Commit (git archive)
# und dist/release-notizen.md aus CHANGELOG.md.
#
#   sh bin/paket-bauen.sh            # nur bauen und prüfen
#   sh bin/paket-bauen.sh v0.2.0     # zusätzlich: passt das Tag zur Version?
#
# Bricht ab, wenn Versionen nicht zusammenpassen oder das ZIP falsch aufgebaut ist.
set -eu
cd "$(dirname "$0")/.."

fehler() { echo "FEHLER: $*" >&2; exit 1; }

KOPF=$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' feuerwehr-beladung.php)
KONSTANTE=$(sed -n "s/^define( 'FWB_VERSION', '\([0-9.]*\)' );/\1/p" feuerwehr-beladung.php)
[ -n "$KOPF" ] || fehler "Version im Plugin-Kopf nicht gefunden."
[ "$KOPF" = "$KONSTANTE" ] || fehler "Plugin-Kopf ($KOPF) und FWB_VERSION ($KONSTANTE) unterscheiden sich."

if [ "${1:-}" != "" ]; then
	TAG_VERSION=${1#v}
	[ "$TAG_VERSION" = "$KOPF" ] || fehler "Tag $1 passt nicht zur Version $KOPF im Plugin."
	grep -q "^## $KOPF " CHANGELOG.md || fehler "CHANGELOG.md hat keinen Abschnitt „## $KOPF …“."
	if grep "^## $KOPF " CHANGELOG.md | grep -qi "nicht veröffentlicht"; then
		fehler "CHANGELOG.md: Abschnitt $KOPF ist noch als „nicht veröffentlicht“ markiert."
	fi
fi

rm -rf dist && mkdir dist
git archive --format=zip --prefix=feuerwehr-beladung/ -o dist/feuerwehr-beladung.zip HEAD

# Prüfen: richtiger Ordner, Hauptdatei vorhanden, keine Entwicklungsdateien.
INHALT=$(unzip -Z1 dist/feuerwehr-beladung.zip)
echo "$INHALT" | grep -qx 'feuerwehr-beladung/feuerwehr-beladung.php' || fehler "Hauptdatei fehlt im ZIP."
echo "$INHALT" | grep -v '^feuerwehr-beladung/' && fehler "Dateien außerhalb des Ordners feuerwehr-beladung/."
for verboten in tests/ bin/ .github/ vendor/ node_modules/ CLAUDE.md composer.json phpunit.xml.dist; do
	echo "$INHALT" | grep -q "^feuerwehr-beladung/$verboten" && fehler "$verboten gehört nicht ins ZIP."
done

# Release-Text: der Abschnitt dieser Version aus CHANGELOG.md (ohne Überschrift).
awk -v v="$KOPF" '
	/^## / { drin = ( $2 == v ); next }
	drin { print }
' CHANGELOG.md > dist/release-notizen.md

echo "Version $KOPF – $(echo "$INHALT" | wc -l | tr -d ' ') Einträge im ZIP – dist/feuerwehr-beladung.zip"
