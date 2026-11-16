/**
 * Lernseite: reine Logik ohne DOM (wird mit node --test geprüft).
 *
 * Zustand = wo das Mitglied gerade ist:
 *   { f: Fahrzeug-ID, a: Ansicht-ID, p: 'info'|'suche', fach: Fach-ID, b: Bild-ID, g: Platz-ID }
 * Ebenen (für die Zurück-Taste):
 *   1 Übersicht  →  2 Fahrzeug (+ a)  →  3 Info/Suche (p) oder Fach (+ b)  →  4 Gerät (g)
 * Ansicht (a) und Bild (b) sind "seitliche" Wechsel innerhalb einer Ebene.
 */

const SCHLUESSEL = [ 'f', 'a', 'p', 'fach', 'b', 'g' ];

export function zustandAusHash( hash ) {
	const z = {};
	const text = String( hash || '' ).replace( /^#/, '' );
	for ( const teil of text.split( '&' ) ) {
		const [ k, v ] = teil.split( '=' );
		if ( SCHLUESSEL.includes( k ) && v ) {
			z[ k ] = decodeURIComponent( v );
		}
	}
	if ( z.f && ! /^\d+$/.test( z.f ) ) {
		delete z.f;
	}
	if ( z.p && ! [ 'info', 'suche' ].includes( z.p ) ) {
		delete z.p;
	}
	return bereinigen( z );
}

export function hashAusZustand( z ) {
	const teile = SCHLUESSEL.filter( ( k ) => z[ k ] ).map( ( k ) => k + '=' + encodeURIComponent( z[ k ] ) );
	return teile.length ? '#' + teile.join( '&' ) : '';
}

/** Entfernt, was ohne Elternteil keinen Sinn ergibt. */
export function bereinigen( z ) {
	const r = {};
	if ( ! z.f ) {
		return r;
	}
	r.f = String( z.f );
	if ( z.a ) {
		r.a = z.a;
	}
	if ( z.fach ) {
		r.fach = z.fach;
		if ( z.b ) {
			r.b = z.b;
		}
		if ( z.g ) {
			r.g = z.g;
		}
	} else if ( z.p ) {
		r.p = z.p;
	}
	return r;
}

/** Ebene 1–4 (siehe oben). */
export function ebene( z ) {
	if ( ! z.f ) {
		return 1;
	}
	if ( z.g ) {
		return 4;
	}
	if ( z.fach || z.p ) {
		return 3;
	}
	return 2;
}

/**
 * Die Zustände aller Ebenen bis z – so baut die Lernseite beim direkten
 * Aufruf eines Links die Browser-Historie auf, damit "Zurück" immer genau
 * eine Ebene nach oben führt.
 */
export function pfad( z ) {
	z = bereinigen( z );
	const liste = [ {} ];
	if ( z.f ) {
		liste.push( { f: z.f, ...( z.a ? { a: z.a } : {} ) } );
	}
	if ( z.p ) {
		liste.push( { ...liste[ liste.length - 1 ], p: z.p } );
	}
	if ( z.fach ) {
		const fach = { ...liste[ liste.length - 1 ], fach: z.fach, ...( z.b ? { b: z.b } : {} ) };
		liste.push( fach );
		if ( z.g ) {
			liste.push( { ...fach, g: z.g } );
		}
	}
	return liste;
}

/** Wie viele Ebenen haben zwei Zustände gemeinsam? */
export function gemeinsameEbenen( a, b ) {
	const pa = pfad( a );
	const pb = pfad( b );
	let n = 0;
	while ( n < pa.length && n < pb.length && gleicheEbene( pa[ n ], pb[ n ] ) ) {
		n++;
	}
	return n;
}

/**
 * Sprung quer durch die Ebenen (z. B. von G3/Leine zu G1/Leine): Der aktuelle
 * Eintrag wird ersetzt, darunter liegende Einträge bleiben veraltet stehen
 * (Fach G3). Diese Umleitungen sagen, was dort stattdessen zu zeigen ist.
 * Ergebnis: [{ tiefe, alt, neu }] mit tiefe = Index des Eintrags (0 = Übersicht).
 */
export function sprungUmleitungen( vorher, ziel ) {
	const pv = pfad( vorher );
	const pz = pfad( ziel );
	const umleitungen = [];
	for ( let i = gemeinsameEbenen( vorher, ziel ); i < pv.length - 1 && i < pz.length; i++ ) {
		umleitungen.push( { tiefe: i, alt: hashAusZustand( pv[ i ] ), neu: hashAusZustand( pz[ i ] ) } );
	}
	return umleitungen;
}

function gleicheEbene( x, y ) {
	// Seitliche Wechsel (Ansicht, Bild) zählen nicht als andere Ebene.
	return x.f === y.f && x.p === y.p && x.fach === y.fach && x.g === y.g;
}

/* ------------------------------ Fahrzeug ------------------------------ */

export function fachVon( fahrzeug, fachId ) {
	return fahrzeug.faecher.find( ( f ) => f.id === fachId ) || null;
}

export function platzVon( fach, platzId ) {
	return fach ? fach.plaetze.find( ( p ) => p.id === platzId ) || null : null;
}

/** Schalter zeigt auf ein späteres Bild = öffnen, sonst schließen. */
export function schalterRichtung( fach, vonBildId, zielBildId ) {
	const von = fach.bilder.findIndex( ( b ) => b.id === vonBildId );
	const zu = fach.bilder.findIndex( ( b ) => b.id === zielBildId );
	return zu > von ? 'auf' : 'zu';
}

export function schalterSymbol( art, richtung ) {
	if ( 'schwenk' === art ) {
		return 'auf' === richtung ? '↻' : '↺';
	}
	return 'auf' === richtung ? '⇣' : '⇡';
}

export function schalterText( art, richtung ) {
	const was = 'schwenk' === art ? 'Schwenkwand' : 'Auszug';
	if ( 'schwenk' === art ) {
		return was + ( 'auf' === richtung ? ' öffnen' : ' schließen' );
	}
	return was + ( 'auf' === richtung ? ' herausziehen' : ' einschieben' );
}

/**
 * Welches Bild zeigen, wenn ein Gerät gewählt wird? Das aktuelle, wenn das
 * Gerät dort markiert ist – sonst das erste Bild, auf dem es markiert ist.
 */
export function bildFuerPlatz( fach, aktuellesBildId, platzId ) {
	const aktuell = fach.bilder.find( ( b ) => b.id === aktuellesBildId ) || fach.bilder[ 0 ];
	if ( ! platzId || ! aktuell || aktuell.markierungen.some( ( m ) => m.platz_id === platzId ) ) {
		return aktuell ? aktuell.id : null;
	}
	const anderes = fach.bilder.find( ( b ) => b.markierungen.some( ( m ) => m.platz_id === platzId ) );
	return anderes ? anderes.id : aktuell.id;
}

/** Alle Fächer dieses Fahrzeugs, in denen ein Gerät liegt. */
export function orteVonGeraet( fahrzeug, uid ) {
	const orte = [];
	for ( const fach of fahrzeug.faecher ) {
		for ( const platz of fach.plaetze ) {
			if ( platz.geraet === uid ) {
				orte.push( { fachId: fach.id, kuerzel: fach.kuerzel, name: fach.name, platzId: platz.id, menge: platz.menge, lage: platz.lage } );
			}
		}
	}
	return orte;
}

/* ------------------------------ Suche ------------------------------ */

/** Kleinbuchstaben, ä→ae usw., ohne Sonderzeichen – damit "schluessel" auch "Schlüssel" findet. */
export function normalisieren( text ) {
	return String( text || '' )
		.toLowerCase()
		.replace( /ä/g, 'ae' )
		.replace( /ö/g, 'oe' )
		.replace( /ü/g, 'ue' )
		.replace( /ß/g, 'ss' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, ' ' )
		.trim();
}

/**
 * Sucht Geräte eines Fahrzeugs nach Name und Suchbegriffen.
 * Ergebnis: [{ uid, name, orte: [...] }], beste Treffer zuerst.
 */
export function suchen( fahrzeug, text ) {
	const woerter = normalisieren( text ).split( ' ' ).filter( Boolean );
	if ( ! woerter.length ) {
		return [];
	}
	const treffer = [];
	for ( const [ uid, g ] of Object.entries( fahrzeug.geraete ) ) {
		const name = normalisieren( g.name );
		const alles = name + ' ' + normalisieren( g.suchbegriffe ) + ' ' + normalisieren( g.beschreibung );
		if ( ! woerter.every( ( w ) => alles.includes( w ) ) ) {
			continue;
		}
		let punkte = 0;
		for ( const w of woerter ) {
			if ( name.startsWith( w ) ) {
				punkte += 3;
			} else if ( name.split( ' ' ).some( ( teil ) => teil.startsWith( w ) ) ) {
				punkte += 2;
			} else if ( name.includes( w ) ) {
				punkte += 1;
			}
		}
		const orte = orteVonGeraet( fahrzeug, uid );
		if ( orte.length ) {
			treffer.push( { uid, name: g.name, orte, punkte } );
		}
	}
	treffer.sort( ( x, y ) => y.punkte - x.punkte || x.name.localeCompare( y.name, 'de' ) );
	return treffer.map( ( { punkte, ...rest } ) => rest );
}

/* ------------------------------ Darstellung ------------------------------ */

/**
 * Größe des Fotos in einem Rahmen: so groß wie möglich, ohne Verzerrung.
 * Sehr breite Fotos (Fahrzeugseite) im Hochformat dürfen breiter als der
 * Bildschirm werden und werden dann seitlich gewischt.
 */
export function buehnenGroesse( rahmenBreite, rahmenHoehe, verhaeltnis ) {
	const r = verhaeltnis > 0 ? verhaeltnis : 4 / 3;
	let breite = Math.min( rahmenBreite, rahmenHoehe * r );
	const hochformat = rahmenHoehe > rahmenBreite;
	let wischen = false;
	if ( hochformat && r > 1.6 ) {
		// Höhe auf gut die Hälfte des Rahmens bringen, höchstens 2,4-fache Bildschirmbreite.
		const ziel = Math.min( rahmenHoehe * 0.55 * r, rahmenBreite * 2.4 );
		if ( ziel > breite * 1.15 ) {
			breite = ziel;
			wischen = true;
		}
	}
	return { breite: Math.round( breite ), hoehe: Math.round( breite / r ), wischen };
}
