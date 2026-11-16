/**
 * Markierer: Foto mit Rechtecken (Fächer oder Geräte) und Punkten (Schalter).
 *
 * - Auf freier Fläche mit der Maus (oder dem Finger) ein Rechteck aufziehen.
 * - Rechteck anklicken = auswählen, ziehen = verschieben.
 * - An den Ecken ziehen = Größe ändern.
 * - Pfeiltasten verschieben das ausgewählte Rechteck, Entf löscht es.
 *
 * Während des Ziehens wird nur die Anzeige verändert; erst beim Loslassen
 * meldet der Markierer die neue Position an den Editor.
 */

import { ausPunkten, verschieben, groesseAendern, punktBegrenzen } from '@fwb/editor/modell';

const SCHWELLE = 0.4; // Prozent – darunter gilt eine Bewegung als Klick.

function prozent( ereignis, buehne ) {
	const r = buehne.getBoundingClientRect();
	return {
		x: ( ( ereignis.clientX - r.left ) / r.width ) * 100,
		y: ( ( ereignis.clientY - r.top ) / r.height ) * 100,
	};
}

function setzeRechteck( element, r ) {
	element.style.left = r.x + '%';
	element.style.top = r.y + '%';
	element.style.width = r.b + '%';
	element.style.height = r.h + '%';
}

export function markierer( optionen ) {
	const {
		bild,
		rechtecke = [],
		punkte = [],
		auswahl = null,
		zeichnen = false,
		punktSetzen = false,
		beiNeu = () => {},
		beiPunktNeu = () => {},
		beiAendern = () => {},
		beiPunktAendern = () => {},
		beiAuswahl = () => {},
		beiLoeschen = () => {},
	} = optionen;

	const buehne = document.createElement( 'div' );
	buehne.className = 'fwb-buehne' + ( zeichnen ? ' fwb-zeichnen' : '' ) + ( punktSetzen ? ' fwb-punkt-setzen' : '' );
	buehne.style.aspectRatio = String( bild.verhaeltnis || 4 / 3 );
	buehne.tabIndex = 0;
	buehne.setAttribute( 'role', 'application' );
	buehne.setAttribute( 'aria-label', 'Foto mit Markierungen. Auf freier Fläche ziehen, um eine Markierung aufzuziehen.' );

	if ( bild.url ) {
		const img = document.createElement( 'img' );
		img.src = bild.url;
		img.alt = '';
		img.draggable = false;
		img.className = 'fwb-buehne-bild';
		buehne.append( img );
	} else {
		const leer = document.createElement( 'div' );
		leer.className = 'fwb-buehne-leer';
		leer.textContent = bild.platzhalterText || 'Noch kein Foto';
		buehne.append( leer );
	}

	const elemente = new Map();
	for ( const r of rechtecke ) {
		const rahmen = document.createElement( 'div' );
		rahmen.className = 'fwb-rahmen' + ( r.id === auswahl ? ' ist-ausgewaehlt' : '' ) + ( r.klasse ? ' ' + r.klasse : '' );
		rahmen.dataset.id = r.id;
		if ( r.gruppe ) {
			// z. B. das Gerät: Liste und Foto heben so alle Stellen gemeinsam hervor.
			rahmen.dataset.gruppe = r.gruppe;
		}
		setzeRechteck( rahmen, r );
		const text = document.createElement( 'span' );
		text.className = 'fwb-rahmen-text';
		text.textContent = r.text || '';
		rahmen.append( text );
		for ( const ecke of [ 'nw', 'ne', 'sw', 'se' ] ) {
			const griff = document.createElement( 'span' );
			griff.className = 'fwb-griff fwb-griff-' + ecke;
			griff.dataset.ecke = ecke;
			rahmen.append( griff );
		}
		rahmen.title = r.text || '';
		buehne.append( rahmen );
		elemente.set( r.id, { element: rahmen, daten: r } );
	}

	for ( const p of punkte ) {
		const knopf = document.createElement( 'span' );
		knopf.className = 'fwb-punkt' + ( p.id === auswahl ? ' ist-ausgewaehlt' : '' ) + ( p.klasse ? ' ' + p.klasse : '' );
		knopf.dataset.punkt = p.id;
		knopf.style.left = p.x + '%';
		knopf.style.top = p.y + '%';
		knopf.textContent = p.symbol || '•';
		knopf.title = p.text || '';
		buehne.append( knopf );
		elemente.set( p.id, { element: knopf, daten: p } );
	}

	let zug = null;

	buehne.addEventListener( 'pointerdown', ( e ) => {
		if ( e.button !== 0 ) {
			return;
		}
		const start = prozent( e, buehne );
		const griff = e.target.closest( '.fwb-griff' );
		const rahmen = e.target.closest( '.fwb-rahmen' );
		const punkt = e.target.closest( '.fwb-punkt' );

		// Im Setz- und Zeichenmodus zählt nur die Stelle, nicht was darunter liegt:
		// Schalter und kleine Geräte sitzen oft auf oder in anderen Markierungen.
		if ( punktSetzen ) {
			beiPunktNeu( punktBegrenzen( start ) );
			e.preventDefault();
			return;
		}
		if ( zeichnen ) {
			const neu = document.createElement( 'div' );
			neu.className = 'fwb-rahmen ist-neu';
			setzeRechteck( neu, { x: start.x, y: start.y, b: 0, h: 0 } );
			buehne.append( neu );
			zug = { art: 'zeichnen', start, element: neu };
		} else if ( punkt ) {
			zug = { art: 'punkt', id: punkt.dataset.punkt, start, element: punkt, daten: elemente.get( punkt.dataset.punkt ).daten };
		} else if ( griff && rahmen ) {
			zug = { art: 'groesse', ecke: griff.dataset.ecke, id: rahmen.dataset.id, start, element: rahmen, daten: elemente.get( rahmen.dataset.id ).daten };
		} else if ( rahmen ) {
			zug = { art: 'schieben', id: rahmen.dataset.id, start, element: rahmen, daten: elemente.get( rahmen.dataset.id ).daten };
		} else {
			zug = { art: 'leer', start };
		}
		buehne.setPointerCapture( e.pointerId );
		buehne.focus( { preventScroll: true } );
		e.preventDefault();
	} );

	buehne.addEventListener( 'pointermove', ( e ) => {
		if ( ! zug ) {
			return;
		}
		const jetzt = prozent( e, buehne );
		const dx = jetzt.x - zug.start.x;
		const dy = jetzt.y - zug.start.y;
		zug.bewegt = zug.bewegt || Math.abs( dx ) > SCHWELLE || Math.abs( dy ) > SCHWELLE;
		if ( ! zug.bewegt ) {
			return;
		}
		if ( 'zeichnen' === zug.art ) {
			zug.ergebnis = ausPunkten( zug.start, jetzt );
			setzeRechteck( zug.element, zug.ergebnis );
		} else if ( 'schieben' === zug.art ) {
			zug.ergebnis = verschieben( zug.daten, dx, dy );
			setzeRechteck( zug.element, zug.ergebnis );
		} else if ( 'groesse' === zug.art ) {
			zug.ergebnis = groesseAendern( zug.daten, zug.ecke, dx, dy );
			setzeRechteck( zug.element, zug.ergebnis );
		} else if ( 'punkt' === zug.art ) {
			zug.ergebnis = punktBegrenzen( { x: zug.daten.x + dx, y: zug.daten.y + dy } );
			zug.element.style.left = zug.ergebnis.x + '%';
			zug.element.style.top = zug.ergebnis.y + '%';
		}
	} );

	const beenden = ( abbrechen ) => {
		if ( ! zug ) {
			return;
		}
		const z = zug;
		zug = null;
		if ( abbrechen ) {
			if ( 'zeichnen' === z.art ) {
				z.element.remove();
			}
			return;
		}
		if ( 'zeichnen' === z.art ) {
			if ( z.ergebnis && z.ergebnis.b >= 1.5 && z.ergebnis.h >= 1.5 ) {
				beiNeu( z.ergebnis );
			} else {
				z.element.remove();
			}
		} else if ( 'leer' === z.art ) {
			beiAuswahl( null );
		} else if ( z.bewegt && z.ergebnis ) {
			if ( 'punkt' === z.art ) {
				beiPunktAendern( z.id, z.ergebnis );
			} else {
				beiAendern( z.id, z.ergebnis );
			}
			beiAuswahl( z.id );
		} else {
			beiAuswahl( z.id );
		}
	};
	buehne.addEventListener( 'pointerup', () => beenden( false ) );
	buehne.addEventListener( 'pointercancel', () => beenden( true ) );

	buehne.addEventListener( 'keydown', ( e ) => {
		const gewaehlt = auswahl ? elemente.get( auswahl ) : null;
		if ( ! gewaehlt ) {
			return;
		}
		if ( 'Delete' === e.key || 'Backspace' === e.key ) {
			e.preventDefault();
			beiLoeschen( auswahl );
			return;
		}
		const schritt = e.shiftKey ? 2 : 0.5;
		const bewegung = { ArrowLeft: [ -schritt, 0 ], ArrowRight: [ schritt, 0 ], ArrowUp: [ 0, -schritt ], ArrowDown: [ 0, schritt ] }[ e.key ];
		if ( ! bewegung ) {
			return;
		}
		e.preventDefault();
		// Anzeige und gemerkte Daten sofort nachführen, damit mehrere Tastendrücke sich addieren.
		const d = gewaehlt.daten;
		if ( 'b' in d ) {
			const neu = verschieben( d, bewegung[ 0 ], bewegung[ 1 ] );
			Object.assign( d, neu );
			setzeRechteck( gewaehlt.element, neu );
			beiAendern( auswahl, neu );
		} else {
			const neu = punktBegrenzen( { x: d.x + bewegung[ 0 ], y: d.y + bewegung[ 1 ] } );
			Object.assign( d, neu );
			gewaehlt.element.style.left = neu.x + '%';
			gewaehlt.element.style.top = neu.y + '%';
			beiPunktAendern( auswahl, neu );
		}
	} );

	return buehne;
}
