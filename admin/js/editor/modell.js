/**
 * Reine Logik des Editors (kein DOM). Wird auch mit node --test geprüft.
 * Positionen sind immer Prozent des Fotos (0–100).
 */

const ZEICHEN = 'abcdefghijklmnopqrstuvwxyz0123456789';

export function neueId( praefix ) {
	let id = praefix + '_';
	const zufall = new Uint32Array( 10 );
	globalThis.crypto.getRandomValues( zufall );
	for ( const z of zufall ) {
		id += ZEICHEN[ z % ZEICHEN.length ];
	}
	return id;
}

export function klonen( wert ) {
	return JSON.parse( JSON.stringify( wert ) );
}

export function leeresDokument() {
	return {
		schema: 1,
		steckbrief: { foto_id: 0, kurz: '', felder: [] },
		ansichten: [],
		faecher: [],
	};
}

/** Ergänzt fehlende Teile, damit der Editor nie an undefined scheitert. */
export function vervollstaendigen( dok ) {
	const d = Object.assign( leeresDokument(), dok || {} );
	d.steckbrief = Object.assign( { foto_id: 0, kurz: '', felder: [] }, d.steckbrief || {} );
	d.ansichten = Array.isArray( d.ansichten ) ? d.ansichten : [];
	d.faecher = Array.isArray( d.faecher ) ? d.faecher : [];
	for ( const a of d.ansichten ) {
		a.bereiche = Array.isArray( a.bereiche ) ? a.bereiche : [];
	}
	for ( const f of d.faecher ) {
		f.plaetze = Array.isArray( f.plaetze ) ? f.plaetze : [];
		f.bilder = Array.isArray( f.bilder ) ? f.bilder : [];
		for ( const b of f.bilder ) {
			b.markierungen = Array.isArray( b.markierungen ) ? b.markierungen : [];
			b.schalter = Array.isArray( b.schalter ) ? b.schalter : [];
		}
	}
	return d;
}

export const UEBLICHE_FELDER = [ 'Fahrzeugtyp', 'Funkrufname', 'Besatzung', 'Pumpe', 'Löschwassertank', 'Schaummittel', 'Baujahr' ];

/* ------------------------------ Rechtecke ------------------------------ */

const MIN = 1;

function runden( z ) {
	return Math.round( z * 100 ) / 100;
}

export function begrenzen( r ) {
	const b = Math.min( 100, Math.max( MIN, r.b ) );
	const h = Math.min( 100, Math.max( MIN, r.h ) );
	const x = Math.min( 100 - b, Math.max( 0, r.x ) );
	const y = Math.min( 100 - h, Math.max( 0, r.y ) );
	return { x: runden( x ), y: runden( y ), b: runden( b ), h: runden( h ) };
}

/** Rechteck aus zwei Punkten (beim Aufziehen mit der Maus). */
export function ausPunkten( p1, p2 ) {
	const x1 = Math.max( 0, Math.min( 100, Math.min( p1.x, p2.x ) ) );
	const y1 = Math.max( 0, Math.min( 100, Math.min( p1.y, p2.y ) ) );
	const x2 = Math.max( 0, Math.min( 100, Math.max( p1.x, p2.x ) ) );
	const y2 = Math.max( 0, Math.min( 100, Math.max( p1.y, p2.y ) ) );
	return begrenzen( { x: x1, y: y1, b: x2 - x1, h: y2 - y1 } );
}

export function verschieben( r, dx, dy ) {
	return begrenzen( { x: r.x + dx, y: r.y + dy, b: r.b, h: r.h } );
}

/**
 * Größe über eine Ecke ändern. ecke: 'nw' | 'ne' | 'sw' | 'se'.
 * Die gegenüberliegende Ecke bleibt fest.
 */
export function groesseAendern( r, ecke, dx, dy ) {
	let links = r.x;
	let oben = r.y;
	let rechts = r.x + r.b;
	let unten = r.y + r.h;
	if ( ecke.includes( 'w' ) ) {
		links = Math.min( rechts - MIN, Math.max( 0, links + dx ) );
	} else {
		rechts = Math.max( links + MIN, Math.min( 100, rechts + dx ) );
	}
	if ( ecke.includes( 'n' ) ) {
		oben = Math.min( unten - MIN, Math.max( 0, oben + dy ) );
	} else {
		unten = Math.max( oben + MIN, Math.min( 100, unten + dy ) );
	}
	return begrenzen( { x: links, y: oben, b: rechts - links, h: unten - oben } );
}

export function punktBegrenzen( p ) {
	return { x: runden( Math.min( 100, Math.max( 0, p.x ) ) ), y: runden( Math.min( 100, Math.max( 0, p.y ) ) ) };
}

/* ------------------------------ Fächer ------------------------------ */

export function freiesKuerzel( faecher ) {
	const vorhanden = new Set( faecher.map( ( f ) => f.kuerzel ) );
	for ( let n = 1; n < 1000; n++ ) {
		if ( ! vorhanden.has( 'G' + n ) ) {
			return 'G' + n;
		}
	}
	return 'G?';
}

export function neuesFach( dok ) {
	const fach = {
		id: neueId( 'f' ),
		kuerzel: freiesKuerzel( dok.faecher ),
		name: '',
		plaetze: [],
		bilder: [ { id: neueId( 'b' ), titel: 'Fach geöffnet', foto_id: 0, skizze: '', markierungen: [], schalter: [] } ],
	};
	dok.faecher.push( fach );
	return fach;
}

export function fachLoeschen( dok, fachId ) {
	dok.faecher = dok.faecher.filter( ( f ) => f.id !== fachId );
	for ( const a of dok.ansichten ) {
		a.bereiche = a.bereiche.filter( ( r ) => r.fach_id !== fachId );
	}
}

/**
 * Neues Bild für ein Fach. Die Markierungen des Vorlagebildes werden als
 * Vorlage übernommen (mit neuen IDs), Schalter nicht.
 */
export function neuesBild( fach, vorlage ) {
	const bild = {
		id: neueId( 'b' ),
		titel: 'Bild ' + ( fach.bilder.length + 1 ),
		foto_id: 0,
		skizze: '',
		markierungen: vorlage ? vorlage.markierungen.map( ( m ) => ( { ...m, id: neueId( 'm' ) } ) ) : [],
		schalter: [],
	};
	fach.bilder.push( bild );
	return bild;
}

export function bildLoeschen( fach, bildId ) {
	if ( fach.bilder.length <= 1 ) {
		return false;
	}
	fach.bilder = fach.bilder.filter( ( b ) => b.id !== bildId );
	for ( const b of fach.bilder ) {
		b.schalter = b.schalter.filter( ( s ) => s.ziel_bild_id !== bildId );
	}
	return true;
}

export function platzLoeschen( fach, platzId ) {
	fach.plaetze = fach.plaetze.filter( ( p ) => p.id !== platzId );
	for ( const b of fach.bilder ) {
		b.markierungen = b.markierungen.filter( ( m ) => m.platz_id !== platzId );
	}
}

/** Zeigt ein Schalter "öffnen" (auf ein späteres Bild) oder "schließen"? */
export function schalterRichtung( fach, vonBildId, zielBildId ) {
	const von = fach.bilder.findIndex( ( b ) => b.id === vonBildId );
	const zu = fach.bilder.findIndex( ( b ) => b.id === zielBildId );
	return zu > von ? 'auf' : 'zu';
}

/** Plätze, die auf keinem Bild markiert sind. */
export function unmarkiertePlaetze( fach ) {
	const markiert = new Set();
	for ( const b of fach.bilder ) {
		for ( const m of b.markierungen ) {
			markiert.add( m.platz_id );
		}
	}
	return fach.plaetze.filter( ( p ) => ! markiert.has( p.id ) );
}

/** Verschiebt ein Element einer Liste um eine Stelle (richtung -1 oder +1). */
export function verschiebeInListe( liste, index, richtung ) {
	const ziel = index + richtung;
	if ( ziel < 0 || ziel >= liste.length ) {
		return false;
	}
	const [ element ] = liste.splice( index, 1 );
	liste.splice( ziel, 0, element );
	return true;
}

/* ------------------------------ Markierungen ------------------------------ */

/**
 * Wo ist ein Gerät markiert? [{ nummer, bildId, anzahl }] für jedes Bild mit
 * mindestens einer Markierung (nummer = 1, 2, … in der Reihenfolge der Bilder).
 */
export function markierungsUebersicht( fach, platzId ) {
	const ergebnis = [];
	fach.bilder.forEach( ( b, i ) => {
		const anzahl = b.markierungen.filter( ( m ) => m.platz_id === platzId ).length;
		if ( anzahl ) {
			ergebnis.push( { nummer: i + 1, bildId: b.id, anzahl } );
		}
	} );
	return ergebnis;
}

/** Bilder des Fachs (außer bildId), auf denen das Gerät noch gar nicht markiert ist. */
export function bilderOhneGeraet( fach, bildId, platzId ) {
	return fach.bilder.filter( ( b ) => b.id !== bildId && ! b.markierungen.some( ( m ) => m.platz_id === platzId ) );
}

/**
 * Überträgt eine Markierung an derselben Stelle auf die anderen Bilder des
 * Fachs, auf denen das Gerät noch nicht markiert ist. Gibt die Zahl der Bilder zurück.
 */
export function markierungUebertragen( fach, bildId, markierungId ) {
	const quelle = fach.bilder.find( ( b ) => b.id === bildId );
	const m = quelle ? quelle.markierungen.find( ( x ) => x.id === markierungId ) : null;
	if ( ! m ) {
		return 0;
	}
	const ziele = bilderOhneGeraet( fach, bildId, m.platz_id );
	for ( const b of ziele ) {
		b.markierungen.push( { ...m, id: neueId( 'm' ) } );
	}
	return ziele.length;
}

/* ------------------------------ Was fehlt noch? ------------------------------ */

function hatBild( fotoId, skizze ) {
	return !! fotoId || !! skizze;
}

/**
 * Liste offener Punkte eines Fahrzeugs, jeweils mit Sprungziel für den Editor:
 * { text, ziel: { reiter, fachId?, bildId?, ansichtId? } }
 */
export function offenePunkte( dok ) {
	const punkte = [];
	const neu = ( text, ziel ) => punkte.push( { text, ziel } );

	if ( ! dok.faecher.length ) {
		neu( 'Noch kein Gerätefach angelegt.', { reiter: 'faecher' } );
	}
	const aufAnsicht = new Set();
	for ( const a of dok.ansichten ) {
		for ( const r of a.bereiche ) {
			aufAnsicht.add( r.fach_id );
		}
	}
	for ( const f of dok.faecher ) {
		const ziel = { reiter: 'faecher', fachId: f.id };
		if ( ! f.plaetze.length ) {
			neu( f.kuerzel + ': noch keine Geräte.', ziel );
		}
		const offen = unmarkiertePlaetze( f ).length;
		if ( offen ) {
			neu( f.kuerzel + ': ' + offen + ( 1 === offen ? ' Gerät ist' : ' Geräte sind' ) + ' auf keinem Bild markiert.', ziel );
		}
		f.bilder.forEach( ( b, i ) => {
			if ( ! hatBild( b.foto_id, b.skizze ) ) {
				neu( f.kuerzel + ', Bild „' + b.titel + '“: noch kein Foto.', { ...ziel, bildId: b.id } );
			}
			if ( i > 0 && ! f.bilder.some( ( andere ) => andere.schalter.some( ( s ) => s.ziel_bild_id === b.id ) ) ) {
				neu( f.kuerzel + ', Bild „' + b.titel + '“: kein Schalter führt dorthin – Mitglieder können es nicht öffnen.', { ...ziel, bildId: f.bilder[ 0 ].id } );
			}
		} );
		if ( dok.ansichten.length && ! aufAnsicht.has( f.id ) ) {
			neu( f.kuerzel + ': auf keiner Außenansicht markiert.', { reiter: 'ansichten' } );
		}
	}
	if ( dok.faecher.length && ! dok.ansichten.length ) {
		neu( 'Noch keine Außenansicht (z. B. Fahrerseite) angelegt.', { reiter: 'ansichten' } );
	}
	for ( const a of dok.ansichten ) {
		if ( ! hatBild( a.foto_id, a.skizze ) ) {
			neu( 'Ansicht „' + a.name + '“: noch kein Foto.', { reiter: 'ansichten', ansichtId: a.id } );
		}
	}
	if ( ! dok.steckbrief.foto_id ) {
		neu( 'Steckbrief: noch kein Foto für die Fahrzeugkarte.', { reiter: 'steckbrief' } );
	}
	return punkte;
}

/* ------------------------------ Rückgängig ------------------------------ */

/**
 * Verlauf für Rückgängig/Wiederholen. Gespeichert werden ganze Stände als
 * JSON-Text. Schnelle Änderungen derselben Gruppe (Tippen in einem Feld,
 * Pfeiltasten) werden zu einem Schritt zusammengefasst.
 */
export function neuerVerlauf( start, max = 60 ) {
	return { stand: start, zurueck: [], vor: [], gruppe: null, zeit: 0, max };
}

export function verlaufMerken( v, neu, gruppe = null, zeit = Date.now() ) {
	if ( neu === v.stand ) {
		return false;
	}
	const zusammen = null !== gruppe && gruppe === v.gruppe && zeit - v.zeit < 2000;
	if ( ! zusammen ) {
		v.zurueck.push( v.stand );
		if ( v.zurueck.length > v.max ) {
			v.zurueck.shift();
		}
	}
	v.vor = [];
	v.stand = neu;
	v.gruppe = gruppe;
	v.zeit = zeit;
	return true;
}

export function verlaufZurueck( v ) {
	if ( ! v.zurueck.length ) {
		return null;
	}
	v.vor.push( v.stand );
	v.stand = v.zurueck.pop();
	v.gruppe = null;
	return v.stand;
}

export function verlaufVor( v ) {
	if ( ! v.vor.length ) {
		return null;
	}
	v.zurueck.push( v.stand );
	v.stand = v.vor.pop();
	v.gruppe = null;
	return v.stand;
}
