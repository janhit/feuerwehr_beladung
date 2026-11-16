import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as L from '../../assets/js/lernen/logik.js';

const fahrzeug = {
	faecher: [
		{
			id: 'f_g1',
			kuerzel: 'G1',
			name: 'Geräteraum 1',
			plaetze: [
				{ id: 'p_leine', geraet: 'g_leine', menge: '2', lage: 'Fachboden' },
				{ id: 'p_schluessel', geraet: 'g_schluessel', menge: '1', lage: '' },
				{ id: 'p_hinten', geraet: 'g_lampe', menge: '1', lage: 'hinter der Schwenkwand' },
			],
			bilder: [
				{ id: 'b_zu', markierungen: [ { platz_id: 'p_leine' }, { platz_id: 'p_schluessel' } ], schalter: [] },
				{ id: 'b_auf', markierungen: [ { platz_id: 'p_leine' }, { platz_id: 'p_hinten' } ], schalter: [] },
			],
		},
		{
			id: 'f_g3',
			kuerzel: 'G3',
			name: '',
			plaetze: [ { id: 'p_leine3', geraet: 'g_leine', menge: '1', lage: 'oben' } ],
			bilder: [ { id: 'b_g3', markierungen: [], schalter: [] } ],
		},
	],
	geraete: {
		g_leine: { name: 'Feuerwehrleine', suchbegriffe: 'Seil, Fangleine', beschreibung: '' },
		g_schluessel: { name: 'Hydrantenschlüssel', suchbegriffe: 'Unterflurhydrant', beschreibung: '' },
		g_lampe: { name: 'Handlampe', suchbegriffe: 'Leuchte', beschreibung: 'Akku-Lampe mit Leine zum Umhängen' },
	},
};

test( 'Adresse (Hash) und Zustand passen zusammen', () => {
	const z = { f: '30', a: 'a_seite', fach: 'f_g1', b: 'b_auf', g: 'p_leine' };
	const hash = L.hashAusZustand( z );
	assert.equal( hash, '#f=30&a=a_seite&fach=f_g1&b=b_auf&g=p_leine' );
	assert.deepEqual( L.zustandAusHash( hash ), z );
	assert.equal( L.hashAusZustand( {} ), '' );
} );

test( 'Unsinnige Adressen werden bereinigt', () => {
	assert.deepEqual( L.zustandAusHash( '#f=abc&fach=f_g1' ), {}, 'Fahrzeug-ID muss eine Zahl sein' );
	assert.deepEqual( L.zustandAusHash( '#fach=f_g1&g=p_x' ), {}, 'ohne Fahrzeug kein Fach' );
	assert.deepEqual( L.zustandAusHash( '#f=3&g=p_x' ), { f: '3' }, 'ohne Fach kein Gerät' );
	assert.deepEqual( L.zustandAusHash( '#f=3&p=quiz' ), { f: '3' }, 'unbekannte Ansicht' );
	assert.deepEqual( L.zustandAusHash( '#f=3&p=info&fach=f_g1' ), { f: '3', fach: 'f_g1' }, 'Fach geht vor Info' );
	assert.deepEqual( L.zustandAusHash( '#f=3&boese=1' ), { f: '3' } );
} );

test( 'Ebenen: Übersicht → Fahrzeug → Fach → Gerät', () => {
	assert.equal( L.ebene( {} ), 1 );
	assert.equal( L.ebene( { f: '1' } ), 2 );
	assert.equal( L.ebene( { f: '1', a: 'a_x' } ), 2, 'Ansicht ist keine eigene Ebene' );
	assert.equal( L.ebene( { f: '1', fach: 'f_g1', b: 'b_auf' } ), 3, 'Bild ist keine eigene Ebene' );
	assert.equal( L.ebene( { f: '1', p: 'suche' } ), 3 );
	assert.equal( L.ebene( { f: '1', fach: 'f_g1', g: 'p_leine' } ), 4 );
} );

test( 'Direkter Link baut alle Ebenen auf – Zurück geht genau eine hoch', () => {
	const p = L.pfad( { f: '30', a: 'a_heck', fach: 'f_g1', b: 'b_auf', g: 'p_leine' } );
	assert.deepEqual( p, [
		{},
		{ f: '30', a: 'a_heck' },
		{ f: '30', a: 'a_heck', fach: 'f_g1', b: 'b_auf' },
		{ f: '30', a: 'a_heck', fach: 'f_g1', b: 'b_auf', g: 'p_leine' },
	] );
	assert.equal( L.pfad( { f: '30', p: 'info' } ).length, 3 );
} );

test( 'Gemeinsame Ebenen beim Springen (z. B. „Liegt auch in G3“)', () => {
	const hier = { f: '30', fach: 'f_g1', g: 'p_leine' };
	assert.equal( L.gemeinsameEbenen( hier, { f: '30', fach: 'f_g3', g: 'p_leine3' } ), 2, 'gleiches Fahrzeug, anderes Fach' );
	assert.equal( L.gemeinsameEbenen( hier, { f: '30', fach: 'f_g1', b: 'b_zu', g: 'p_leine' } ), 4, 'nur anderes Bild = gleiche Ebenen' );
	assert.equal( L.gemeinsameEbenen( { f: '30', p: 'suche' }, { f: '30', fach: 'f_g1', g: 'p_leine' } ), 2, 'aus der Suche ins Fach' );
	assert.equal( L.gemeinsameEbenen( hier, { f: '31' } ), 1 );
} );

test( 'Schalter: Richtung, Symbol und Text', () => {
	const fach = fahrzeug.faecher[ 0 ];
	assert.equal( L.schalterRichtung( fach, 'b_zu', 'b_auf' ), 'auf' );
	assert.equal( L.schalterRichtung( fach, 'b_auf', 'b_zu' ), 'zu' );
	assert.equal( L.schalterSymbol( 'schwenk', 'auf' ), '↻' );
	assert.equal( L.schalterSymbol( 'auszug', 'zu' ), '⇡' );
	assert.equal( L.schalterText( 'schwenk', 'auf' ), 'Schwenkwand öffnen' );
	assert.equal( L.schalterText( 'auszug', 'zu' ), 'Auszug einschieben' );
} );

test( 'Gerät wählen zeigt das Bild, auf dem es zu sehen ist', () => {
	const fach = fahrzeug.faecher[ 0 ];
	assert.equal( L.bildFuerPlatz( fach, 'b_zu', 'p_leine' ), 'b_zu', 'auf beiden Bildern → aktuelles bleibt' );
	assert.equal( L.bildFuerPlatz( fach, 'b_zu', 'p_hinten' ), 'b_auf', 'nur hinter der Schwenkwand' );
	assert.equal( L.bildFuerPlatz( fach, null, 'p_schluessel' ), 'b_zu' );
	assert.equal( L.bildFuerPlatz( fahrzeug.faecher[ 1 ], 'b_g3', 'p_leine3' ), 'b_g3', 'nirgends markiert → aktuelles' );
} );

test( 'Suche findet Namen, Teilwörter, Suchbegriffe und Umlaute', () => {
	const namen = ( text ) => L.suchen( fahrzeug, text ).map( ( t ) => t.name );
	assert.deepEqual( namen( 'leine' ), [ 'Feuerwehrleine', 'Handlampe' ], 'Name vor Beschreibung' );
	assert.deepEqual( namen( 'Seil' ), [ 'Feuerwehrleine' ], 'Suchbegriff' );
	assert.deepEqual( namen( 'schlüssel' ), [ 'Hydrantenschlüssel' ] );
	assert.deepEqual( namen( 'SCHLUESSEL' ), [ 'Hydrantenschlüssel' ] );
	assert.deepEqual( namen( 'hand lampe' ), [ 'Handlampe' ], 'alle Wörter müssen passen' );
	assert.deepEqual( namen( '   ' ), [] );
	assert.deepEqual( namen( 'Kettensäge' ), [] );
	const leine = L.suchen( fahrzeug, 'Feuerwehrleine' )[ 0 ];
	assert.deepEqual( leine.orte.map( ( o ) => o.kuerzel ), [ 'G1', 'G3' ], 'alle Fächer' );
} );

test( 'Bühne: so groß wie möglich, breite Fotos am Handy zum Wischen', () => {
	assert.deepEqual( L.buehnenGroesse( 1200, 700, 4 / 3 ), { breite: 933, hoehe: 700, wischen: false } );
	assert.deepEqual( L.buehnenGroesse( 1200, 700, 3 ), { breite: 1200, hoehe: 400, wischen: false } );
	const handy = L.buehnenGroesse( 375, 600, 3 );
	assert.equal( handy.wischen, true );
	assert.ok( handy.breite > 375 && handy.breite <= 900 );
	assert.equal( L.buehnenGroesse( 375, 600, 4 / 3 ).wischen, false, 'Fachfoto passt' );
} );

test( 'Sprung „Liegt auch in G1“: Umleitung für das alte Fach darunter', () => {
	const g3 = { f: '30', a: 'a_s', fach: 'f_g3', g: 'p_leine3' };
	const g1 = { f: '30', a: 'a_s', fach: 'f_g1', b: 'b_zu', g: 'p_leine' };
	assert.deepEqual( L.sprungUmleitungen( g3, g1 ), [
		{ tiefe: 2, alt: '#f=30&a=a_s&fach=f_g3', neu: '#f=30&a=a_s&fach=f_g1&b=b_zu' },
	] );
	// Aus der Suche ins Gerät: der Sucheintrag wird ersetzt, nichts bleibt veraltet.
	assert.deepEqual( L.sprungUmleitungen( { f: '30', p: 'suche' }, g1 ), [] );
	// Vom Fahrzeug direkt ins Gerät: nur tiefer, nichts veraltet.
	assert.deepEqual( L.sprungUmleitungen( { f: '30' }, g1 ), [] );
	// Anderes Gerät im selben Fach: gleiche Ebenen bis zum Fach.
	assert.deepEqual( L.sprungUmleitungen( g1, { ...g1, g: 'p_andere' } ), [] );
} );
