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
