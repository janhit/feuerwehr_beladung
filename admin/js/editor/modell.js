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
