// Ausführen: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as M from '../../admin/js/editor/modell.js';

test( 'neueId hat das Muster, das der Server erwartet', () => {
	const id = M.neueId( 'm' );
	assert.match( id, /^m_[a-z0-9]{10}$/ );
	assert.notEqual( M.neueId( 'm' ), id );
} );

test( 'Rechteck bleibt immer im Bild', () => {
	assert.deepEqual( M.begrenzen( { x: 95, y: -5, b: 20, h: 10 } ), { x: 80, y: 0, b: 20, h: 10 } );
	assert.deepEqual( M.begrenzen( { x: 10, y: 10, b: 0.1, h: 500 } ), { x: 10, y: 0, b: 1, h: 100 } );
} );

test( 'Aufziehen funktioniert in jede Richtung', () => {
	assert.deepEqual( M.ausPunkten( { x: 50, y: 60 }, { x: 10, y: 20 } ), { x: 10, y: 20, b: 40, h: 40 } );
} );

test( 'Verschieben stößt am Rand an', () => {
	assert.deepEqual( M.verschieben( { x: 70, y: 70, b: 20, h: 20 }, 50, 50 ), { x: 80, y: 80, b: 20, h: 20 } );
} );

test( 'Größe ändern über die Ecke oben links hält die Ecke unten rechts fest', () => {
	const r = M.groesseAendern( { x: 20, y: 20, b: 20, h: 20 }, 'nw', -10, -5 );
	assert.deepEqual( r, { x: 10, y: 15, b: 30, h: 25 } );
	const klein = M.groesseAendern( { x: 20, y: 20, b: 20, h: 20 }, 'se', -50, -50 );
	assert.deepEqual( klein, { x: 20, y: 20, b: 1, h: 1 } );
} );

test( 'Neues Bild übernimmt Markierungen mit neuen IDs, aber keine Schalter', () => {
	const dok = M.leeresDokument();
	const fach = M.neuesFach( dok );
	fach.plaetze.push( { id: 'p_aaaaaa', geraet: 'g_aaaaaa', menge: '1', lage: '', notiz: '' } );
	const bild1 = fach.bilder[ 0 ];
	bild1.markierungen.push( { id: 'm_aaaaaa', platz_id: 'p_aaaaaa', x: 1, y: 2, b: 3, h: 4 } );
	const bild2 = M.neuesBild( fach, bild1 );
	bild1.schalter.push( { id: 's_aaaaaa', art: 'schwenk', ziel_bild_id: bild2.id, x: 5, y: 5 } );

	assert.equal( bild2.markierungen.length, 1 );
	assert.notEqual( bild2.markierungen[ 0 ].id, 'm_aaaaaa' );
	assert.equal( bild2.markierungen[ 0 ].platz_id, 'p_aaaaaa' );
	assert.equal( bild2.schalter.length, 0 );
	assert.equal( M.schalterRichtung( fach, bild1.id, bild2.id ), 'auf' );
	assert.equal( M.schalterRichtung( fach, bild2.id, bild1.id ), 'zu' );
} );

test( 'Bild löschen entfernt Schalter, die darauf zeigen; das letzte Bild bleibt', () => {
	const dok = M.leeresDokument();
	const fach = M.neuesFach( dok );
	const bild2 = M.neuesBild( fach, fach.bilder[ 0 ] );
	fach.bilder[ 0 ].schalter.push( { id: 's_bbbbbb', art: 'auszug', ziel_bild_id: bild2.id, x: 1, y: 1 } );
	assert.equal( M.bildLoeschen( fach, bild2.id ), true );
	assert.equal( fach.bilder[ 0 ].schalter.length, 0 );
	assert.equal( M.bildLoeschen( fach, fach.bilder[ 0 ].id ), false );
} );

test( 'Fach löschen entfernt seine Flächen in allen Ansichten', () => {
	const dok = M.leeresDokument();
	const fach = M.neuesFach( dok );
	dok.ansichten.push( { id: 'a_aaaaaa', name: 'Fahrerseite', foto_id: 0, skizze: '', bereiche: [ { id: 'r_aaaaaa', fach_id: fach.id, x: 0, y: 0, b: 10, h: 10 } ] } );
	M.fachLoeschen( dok, fach.id );
	assert.equal( dok.faecher.length, 0 );
	assert.equal( dok.ansichten[ 0 ].bereiche.length, 0 );
} );

test( 'Kürzel werden fortlaufend vergeben und Lücken gefüllt', () => {
	const dok = M.leeresDokument();
	M.neuesFach( dok );
	M.neuesFach( dok );
	dok.faecher[ 0 ].kuerzel = 'GR';
	assert.equal( M.neuesFach( dok ).kuerzel, 'G1' );
} );

test( 'Unmarkierte Plätze werden erkannt', () => {
	const dok = M.leeresDokument();
	const fach = M.neuesFach( dok );
	fach.plaetze.push( { id: 'p_1aaaa', geraet: 'g_1aaaa' }, { id: 'p_2aaaa', geraet: 'g_2aaaa' } );
	fach.bilder[ 0 ].markierungen.push( { id: 'm_1aaaa', platz_id: 'p_1aaaa', x: 0, y: 0, b: 5, h: 5 } );
	assert.deepEqual( M.unmarkiertePlaetze( fach ).map( ( p ) => p.id ), [ 'p_2aaaa' ] );
} );

/* --------------------- Mehrere Bilder, mehrere Stellen --------------------- */

function fachMitZweiBildern() {
	const dok = M.leeresDokument();
	const fach = M.neuesFach( dok );
	fach.plaetze.push( { id: 'p_leine', geraet: 'g_leine', menge: '2', lage: '', notiz: '' } );
	fach.plaetze.push( { id: 'p_lampe', geraet: 'g_lampe', menge: '1', lage: '', notiz: '' } );
	const [ bild1 ] = fach.bilder;
	bild1.markierungen.push( { id: 'm_eins', platz_id: 'p_leine', x: 10, y: 10, b: 5, h: 5, notiz: 'oben links' } );
	bild1.markierungen.push( { id: 'm_zwei', platz_id: 'p_leine', x: 80, y: 80, b: 5, h: 5, notiz: 'unten rechts' } );
	const bild2 = M.neuesBild( fach, bild1 );
	return { dok, fach, bild1, bild2 };
}

test( 'Ein Gerät kann an mehreren Stellen eines Bildes markiert sein', () => {
	const { fach, bild1, bild2 } = fachMitZweiBildern();
	assert.deepEqual( M.markierungsUebersicht( fach, 'p_leine' ), [
		{ nummer: 1, bildId: bild1.id, anzahl: 2 },
		{ nummer: 2, bildId: bild2.id, anzahl: 2 },
	] );
	assert.deepEqual( M.markierungsUebersicht( fach, 'p_lampe' ), [] );
	// Das neue Bild hat die Notizen übernommen, aber eigene IDs.
	assert.equal( bild2.markierungen[ 0 ].notiz, 'oben links' );
	assert.notEqual( bild2.markierungen[ 0 ].id, 'm_eins' );
} );

test( 'Markierung auf andere Bilder übertragen – nur dorthin, wo das Gerät fehlt', () => {
	const { fach, bild1, bild2 } = fachMitZweiBildern();
	const bild3 = M.neuesBild( fach, null );
	bild1.markierungen.push( { id: 'm_lampe', platz_id: 'p_lampe', x: 40, y: 40, b: 10, h: 10, notiz: '' } );

	assert.deepEqual( M.bilderOhneGeraet( fach, bild1.id, 'p_lampe' ).map( ( b ) => b.id ), [ bild2.id, bild3.id ] );
	assert.equal( M.markierungUebertragen( fach, bild1.id, 'm_lampe' ), 2 );
	assert.equal( bild2.markierungen.filter( ( m ) => 'p_lampe' === m.platz_id ).length, 1 );
	assert.deepEqual( { ...bild3.markierungen[ 0 ], id: 'x' }, { id: 'x', platz_id: 'p_lampe', x: 40, y: 40, b: 10, h: 10, notiz: '' } );

	// Zweites Mal: nichts mehr zu tun, keine Doppelten.
	assert.equal( M.markierungUebertragen( fach, bild1.id, 'm_lampe' ), 0 );
	// Leine liegt auf Bild 2 schon → Bild 3 bekommt nur eine Kopie der gewählten Stelle.
	assert.equal( M.markierungUebertragen( fach, bild1.id, 'm_eins' ), 1 );
	assert.equal( M.markierungUebertragen( fach, bild1.id, 'gibt_es_nicht' ), 0 );
} );

/* ------------------------------ Was fehlt noch? ------------------------------ */

test( 'Leeres Fahrzeug: erster offener Punkt ist das erste Fach', () => {
	const punkte = M.offenePunkte( M.leeresDokument() );
	assert.equal( punkte[ 0 ].ziel.reiter, 'faecher' );
	assert.match( punkte[ 0 ].text, /kein Gerätefach/ );
} );

test( 'Offene Punkte nennen Fach, Bild und Sprungziel', () => {
	const { dok, fach, bild2 } = fachMitZweiBildern();
	fach.plaetze.push( { id: 'p_axt', geraet: 'g_axt', menge: '1', lage: '', notiz: '' } );
	dok.ansichten.push( { id: 'a_seite', name: 'Fahrerseite', foto_id: 0, skizze: 'seite-fahrer', bereiche: [] } );
	const texte = M.offenePunkte( dok ).map( ( p ) => p.text );

	assert.ok( texte.includes( 'G1: 2 Geräte sind auf keinem Bild markiert.' ), texte.join( '\n' ) );
	assert.ok( texte.some( ( t ) => t.startsWith( 'G1, Bild „Bild 2“: kein Schalter' ) ) );
	assert.ok( texte.includes( 'G1, Bild „Fach geöffnet“: noch kein Foto.' ) );
	assert.ok( texte.includes( 'G1: auf keiner Außenansicht markiert.' ) );
	assert.ok( ! texte.some( ( t ) => t.startsWith( 'Ansicht' ) ), 'Skizze zählt als Bild' );

	// Schalter setzen und Fach auf der Ansicht markieren → diese Punkte verschwinden.
	fach.bilder[ 0 ].schalter.push( { id: 's_auf', art: 'schwenk', ziel_bild_id: bild2.id, x: 50, y: 50 } );
	dok.ansichten[ 0 ].bereiche.push( { id: 'r_g1', fach_id: fach.id, x: 1, y: 1, b: 10, h: 10 } );
	const danach = M.offenePunkte( dok ).map( ( p ) => p.text );
	assert.ok( ! danach.some( ( t ) => t.includes( 'kein Schalter' ) ) );
	assert.ok( ! danach.some( ( t ) => t.includes( 'Außenansicht markiert' ) ) );
} );

/* ------------------------------ Rückgängig ------------------------------ */

test( 'Rückgängig und Wiederholen', () => {
	const v = M.neuerVerlauf( 'A' );
	assert.equal( M.verlaufZurueck( v ), null );
	M.verlaufMerken( v, 'B' );
	M.verlaufMerken( v, 'C' );
	assert.equal( M.verlaufZurueck( v ), 'B' );
	assert.equal( M.verlaufZurueck( v ), 'A' );
	assert.equal( M.verlaufVor( v ), 'B' );
	// Neue Änderung verwirft das Wiederholen.
	M.verlaufMerken( v, 'X' );
	assert.equal( M.verlaufVor( v ), null );
	assert.equal( M.verlaufZurueck( v ), 'B' );
	// Gleicher Stand wird nicht gemerkt.
	assert.equal( M.verlaufMerken( v, 'B' ), false );
} );

test( 'Tippen in einem Feld ergibt einen einzigen Rückgängig-Schritt', () => {
	const v = M.neuerVerlauf( '' );
	M.verlaufMerken( v, 'H', 'feld1', 1000 );
	M.verlaufMerken( v, 'HL', 'feld1', 1300 );
	M.verlaufMerken( v, 'HLF', 'feld1', 1600 );
	M.verlaufMerken( v, 'HLF!', 'feld2', 1700 );
	M.verlaufMerken( v, 'HLF!!', 'feld2', 9000 ); // lange Pause → neuer Schritt
	assert.equal( M.verlaufZurueck( v ), 'HLF!' );
	assert.equal( M.verlaufZurueck( v ), 'HLF' );
	assert.equal( M.verlaufZurueck( v ), '' );
} );

test( 'Verlauf ist begrenzt', () => {
	const v = M.neuerVerlauf( '0', 3 );
	for ( let i = 1; i <= 10; i++ ) {
		M.verlaufMerken( v, String( i ) );
	}
	assert.equal( v.zurueck.length, 3 );
} );
