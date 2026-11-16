/**
 * Backend-Editor "Beladung": Steckbrief, Fächer und Geräte, Außenansichten.
 *
 * Der ganze Stand liegt in "dok". Jede Änderung schreibt das Dokument als
 * JSON in das versteckte Feld; gespeichert wird mit dem normalen
 * "Aktualisieren"/"Speichern" von WordPress (oder dem Knopf in der Leiste,
 * der genau diesen Knopf auslöst).
 */

import * as M from '@fwb/editor/modell';
import { markierer } from '@fwb/editor/markierer';
import * as Katalog from '@fwb/editor/katalog';

const datenElement = document.getElementById( 'fwb-editor-daten' );
const feld = document.getElementById( 'fwb-beladung-json' );
const wurzel = document.getElementById( 'fwb-editor' );

if ( datenElement && feld && wurzel ) {
	starten( JSON.parse( datenElement.textContent ) );
}

function starten( daten ) {
	Katalog.einrichten( daten.rest );

	let dok = M.vervollstaendigen( daten.dokument );
	const fotos = daten.fotos || {};
	const katalog = daten.katalog || {};
	const ui = {
		reiter: 'faecher',
		ansichtId: null,
		fachId: null,
		bildId: null,
		auswahl: null,
		modus: null, // { art: 'bereich', fachId } | { art: 'geraet', platzId } | { art: 'schalter', schalterArt, zielBildId }
		angebot: null, // Markierungs-ID, für die "auch auf den anderen Bildern?" gefragt wird
		meldung: '',
		fehltOffen: false,
		zurBuehne: false,
	};
	const verlauf = M.neuerVerlauf( JSON.stringify( dok ) );
	let geaendert = false;
	let absenden = false;

	/* ------------------------------ Hilfen ------------------------------ */

	function el( tag, eigenschaften = {}, ...kinder ) {
		const e = document.createElement( tag );
		for ( const [ k, v ] of Object.entries( eigenschaften ) ) {
			if ( null === v || undefined === v || false === v ) {
				continue;
			}
			if ( k.startsWith( 'on' ) ) {
				e.addEventListener( k.slice( 2 ), v );
			} else if ( 'class' === k ) {
				e.className = v;
			} else if ( 'text' === k ) {
				e.textContent = v;
			} else if ( k.startsWith( 'data-' ) || k.startsWith( 'aria-' ) ) {
				e.setAttribute( k, true === v ? '' : v );
			} else if ( k in e && 'list' !== k ) {
				e[ k ] = v;
			} else {
				e.setAttribute( k, true === v ? '' : v );
			}
		}
		for ( const kind of kinder.flat( Infinity ) ) {
			if ( null !== kind && undefined !== kind && false !== kind ) {
				e.append( kind instanceof Node ? kind : document.createTextNode( String( kind ) ) );
			}
		}
		return e;
	}

	function knopf( text, beiKlick, klasse = 'button', extra = {} ) {
		return el( 'button', { type: 'button', class: klasse, text, onclick: beiKlick, ...extra } );
	}

	let feldZaehler = 0;

	function textFeld( label, wert, beiEingabe, { mehrzeilig = false, max = 200, breit = true, neuZeichnen = false, platzhalter = null } = {} ) {
		const id = 'fwb-feld-' + ( ++feldZaehler );
		const gruppe = 'text:' + label;
		const eingabe = el( mehrzeilig ? 'textarea' : 'input', {
			id,
			class: breit ? 'widefat' : 'regular-text',
			value: wert || '',
			maxLength: max,
			rows: mehrzeilig ? 4 : null,
			type: mehrzeilig ? null : 'text',
			placeholder: platzhalter,
			oninput: ( e ) => {
				beiEingabe( e.target.value );
				gemerkt( false, gruppe );
			},
			onchange: () => {
				if ( neuZeichnen ) {
					zeichnen();
				}
			},
			onkeydown: ( e ) => {
				if ( 'Enter' === e.key && ! mehrzeilig ) {
					e.preventDefault(); // nicht das ganze Formular absenden
				}
			},
		} );
		return el( 'p', { class: 'fwb-feld' }, el( 'label', { for: id, text: label } ), eingabe );
	}

	/**
	 * Nach jeder Änderung aufrufen: schreibt das JSON ins Formular und merkt
	 * den Stand für "Rückgängig". gruppe fasst schnelles Tippen zusammen.
	 */
	function gemerkt( neuZeichnen = true, gruppe = null ) {
		const json = JSON.stringify( dok );
		feld.value = json;
		if ( M.verlaufMerken( verlauf, json, gruppe ) ) {
			geaendert = true;
		}
		if ( neuZeichnen ) {
			zeichnen();
		} else {
			leisteAktualisieren();
		}
	}

	function standLaden( json ) {
		if ( null === json ) {
			return;
		}
		dok = M.vervollstaendigen( JSON.parse( json ) );
		feld.value = json;
		geaendert = true;
		ui.auswahl = null;
		ui.modus = null;
		ui.angebot = null;
		zeichnen();
	}

	function bildDaten( fotoId, skizze, standardVerhaeltnis ) {
		const foto = fotoId ? fotos[ fotoId ] : null;
		if ( foto && foto.url ) {
			return { url: foto.url, verhaeltnis: foto.b && foto.h ? foto.b / foto.h : standardVerhaeltnis };
		}
		if ( skizze && daten.skizzen[ skizze ] ) {
			return { url: daten.skizzen[ skizze ], verhaeltnis: standardVerhaeltnis };
		}
		return { url: null, verhaeltnis: standardVerhaeltnis, platzhalterText: 'Noch kein Foto – Markierungen gehen trotzdem' };
	}

	function fotoWaehlen( beiWahl ) {
		if ( ! window.wp || ! window.wp.media ) {
			window.alert( 'Die Mediathek konnte nicht geladen werden.' );
			return;
		}
		const rahmen = window.wp.media( {
			title: 'Foto auswählen oder hochladen',
			library: { type: 'image' },
			button: { text: 'Foto übernehmen' },
			multiple: false,
		} );
		rahmen.on( 'select', () => {
			const a = rahmen.state().get( 'selection' ).first().toJSON();
			const groesse = ( a.sizes && ( a.sizes.large || a.sizes.full ) ) || a;
			fotos[ a.id ] = { url: groesse.url, b: groesse.width, h: groesse.height };
			beiWahl( a.id );
		} );
		rahmen.open();
	}

	function fotoFeld( fotoId, beiAenderung, { skizze = '' } = {} ) {
		const foto = fotoId ? fotos[ fotoId ] : null;
		return el(
			'div',
			{ class: 'fwb-foto-feld' },
			foto
				? el( 'img', { src: foto.url, alt: '', class: 'fwb-foto-vorschau' } )
				: el( 'span', { class: 'description', text: skizze ? 'Skizze statt Foto.' : 'Kein Foto gewählt.' } ),
			knopf( foto ? 'Foto ändern' : 'Foto wählen oder hochladen', () => fotoWaehlen( ( id ) => beiAenderung( id ) ), foto ? 'button button-small' : 'button' ),
			foto ? knopf( 'Foto entfernen', () => beiAenderung( 0 ), 'button-link fwb-gefahr' ) : null
		);
	}

	function geraetName( uid ) {
		return katalog[ uid ] ? katalog[ uid ].name : '(unbekanntes Gerät)';
	}

	/** Leiste mit Fächern bzw. Ansichten als Knöpfe nebeneinander. */
	function chipLeiste( { titel, eintraege, aktivId, beiWahl, beiNeu, neuText } ) {
		return el(
			'div',
			{ class: 'fwb-chips', role: 'group', 'aria-label': titel },
			el( 'span', { class: 'fwb-chips-titel', text: titel + ':' } ),
			eintraege.map( ( e ) =>
				el(
					'button',
					{
						type: 'button',
						class: 'fwb-chip' + ( e.id === aktivId ? ' ist-aktiv' : '' ) + ( e.warnung ? ' hat-warnung' : '' ),
						'aria-pressed': e.id === aktivId ? 'true' : 'false',
						title: [ e.untertitel, e.warnung ].filter( Boolean ).join( ' · ' ),
						onclick: () => beiWahl( e.id ),
					},
					el( 'strong', { text: e.titel } ),
					e.untertitel ? el( 'span', { class: 'fwb-chip-unter', text: e.untertitel } ) : null,
					e.warnung ? el( 'span', { class: 'fwb-chip-warnung', 'aria-label': e.warnung, text: '!' } ) : null
				)
			),
			knopf( neuText, beiNeu, 'button fwb-chip-neu' )
		);
	}

	function ordnenKnoepfe( liste, index, beiFertig, wort = '' ) {
		return el(
			'span',
			{ class: 'fwb-ordnen' },
			knopf( '↑', () => M.verschiebeInListe( liste, index, -1 ) && beiFertig(), 'button button-small', { title: wort + ' nach oben', 'aria-label': wort + ' nach oben' } ),
			knopf( '↓', () => M.verschiebeInListe( liste, index, 1 ) && beiFertig(), 'button button-small', { title: wort + ' nach unten', 'aria-label': wort + ' nach unten' } )
		);
	}

	function sprungZu( ziel ) {
		ui.reiter = ziel.reiter;
		if ( ziel.fachId ) {
			ui.fachId = ziel.fachId;
			ui.bildId = ziel.bildId || null;
		}
		if ( ziel.ansichtId ) {
			ui.ansichtId = ziel.ansichtId;
		}
		ui.auswahl = null;
		ui.modus = null;
		ui.angebot = null;
		zeichnen();
		wurzel.scrollIntoView( { block: 'start', behavior: 'smooth' } );
	}

	/* ------------------------------ Leiste oben ------------------------------ */

	function speichernKnopfImFormular() {
		// Entwurf: "Speichern" (bleibt Entwurf). Veröffentlicht: "Aktualisieren".
		const entwurf = document.getElementById( 'save-post' );
		if ( entwurf && entwurf.offsetParent ) {
			return entwurf;
		}
		return document.getElementById( 'publish' );
	}

	function leiste() {
		return el(
			'div',
			{ class: 'fwb-leiste' },
			el( 'span', { class: 'fwb-leiste-status', 'aria-live': 'polite' } ),
			el(
				'span',
				{ class: 'fwb-leiste-knoepfe' },
				el( 'button', { type: 'button', class: 'button fwb-zurueck', title: 'Rückgängig (Strg+Z)', 'aria-label': 'Rückgängig', onclick: () => standLaden( M.verlaufZurueck( verlauf ) ) }, '↶', el( 'span', { class: 'fwb-lang', text: ' Rückgängig' } ) ),
				el( 'button', { type: 'button', class: 'button fwb-vor', title: 'Wiederholen (Strg+Umschalt+Z)', 'aria-label': 'Wiederholen', onclick: () => standLaden( M.verlaufVor( verlauf ) ) }, '↷', el( 'span', { class: 'fwb-lang', text: ' Wiederholen' } ) ),
				knopf( 'Speichern', () => {
					const k = speichernKnopfImFormular();
					if ( k ) {
						k.click();
					}
				}, 'button button-primary fwb-speichern' )
			)
		);
	}

	function leisteAktualisieren() {
		const status = wurzel.querySelector( '.fwb-leiste-status' );
		if ( ! status ) {
			return;
		}
		status.textContent = ui.meldung || ( geaendert ? 'Nicht gespeicherte Änderungen' : 'Alles gespeichert' );
		status.classList.toggle( 'ist-ungespeichert', geaendert && ! ui.meldung );
		wurzel.querySelector( '.fwb-zurueck' ).disabled = ! verlauf.zurueck.length;
		wurzel.querySelector( '.fwb-vor' ).disabled = ! verlauf.vor.length;
		wurzel.querySelector( '.fwb-speichern' ).classList.toggle( 'ist-dringend', geaendert );
	}

	function meldung( text ) {
		ui.meldung = text;
		leisteAktualisieren();
		clearTimeout( meldung.zeit );
		meldung.zeit = setTimeout( () => {
			ui.meldung = '';
			leisteAktualisieren();
		}, 4000 );
	}

	/* ------------------------------ Was fehlt noch? ------------------------------ */

	function wasFehlt() {
		const punkte = M.offenePunkte( dok );
		if ( ! punkte.length ) {
			return el( 'p', { class: 'fwb-fehlt fwb-fehlt-fertig', text: '✓ Alles Wichtige ist erledigt.' } );
		}
		const kasten = el(
			'details',
			{ class: 'fwb-fehlt', open: ui.fehltOffen, ontoggle: ( e ) => { ui.fehltOffen = e.target.open; } },
			el( 'summary', {}, 'Was fehlt noch? ', el( 'span', { class: 'fwb-zahl', text: String( punkte.length ) } ) ),
			el(
				'ul',
				{},
				punkte.slice( 0, 30 ).map( ( p ) => el( 'li', {}, knopf( p.text, () => sprungZu( p.ziel ), 'button-link' ) ) )
			),
			punkte.length > 30 ? el( 'p', { class: 'description', text: '… und ' + ( punkte.length - 30 ) + ' weitere.' } ) : null
		);
		return kasten;
	}

	/* ------------------------------ Aufbau ------------------------------ */

	function zeichnen() {
		if ( ui.angebot && ui.auswahl !== ui.angebot ) {
			ui.angebot = null;
		}
		const reiter = [
			[ 'steckbrief', 'Steckbrief' ],
			[ 'faecher', 'Fächer und Geräte', dok.faecher.length ],
			[ 'ansichten', 'Außenansichten', dok.ansichten.length ],
		];
		const kopf = el(
			'div',
			{ class: 'fwb-kopf' },
			el(
				'div',
				{ class: 'fwb-reiter', role: 'tablist' },
				reiter.map( ( [ id, text, zahl ] ) =>
					el(
						'button',
						{
							type: 'button',
							role: 'tab',
							class: 'fwb-reiter-knopf' + ( ui.reiter === id ? ' ist-aktiv' : '' ),
							'aria-selected': ui.reiter === id ? 'true' : 'false',
							onclick: () => {
								ui.reiter = id;
								ui.auswahl = null;
								ui.modus = null;
								zeichnen();
							},
						},
						text,
						undefined !== zahl ? el( 'span', { class: 'fwb-zahl', text: String( zahl ) } ) : null
					)
				)
			)
		);
		let inhalt;
		if ( 'steckbrief' === ui.reiter ) {
			inhalt = steckbrief();
		} else if ( 'ansichten' === ui.reiter ) {
			inhalt = ansichten();
		} else {
			inhalt = faecher();
		}
		// Die Geräteliste scrollt am Rechner für sich – Position über das Neuzeichnen retten.
		const alteListe = wurzel.querySelector( '.fwb-geraete' );
		const listenPosition = alteListe ? alteListe.scrollTop : 0;
		wurzel.replaceChildren( leiste(), wasFehlt(), kopf, el( 'div', { class: 'fwb-inhalt', role: 'tabpanel' }, inhalt ) );
		leisteAktualisieren();

		const liste = wurzel.querySelector( '.fwb-geraete' );
		if ( liste ) {
			liste.scrollTop = listenPosition;
			const karte = liste.querySelector( '.fwb-geraet.ist-modus, .fwb-geraet.ist-aktiv' );
			if ( karte && liste.scrollHeight > liste.clientHeight ) {
				const oben = karte.getBoundingClientRect().top - liste.getBoundingClientRect().top + liste.scrollTop;
				if ( oben < liste.scrollTop || oben + karte.offsetHeight > liste.scrollTop + liste.clientHeight ) {
					liste.scrollTop = Math.max( 0, oben - 40 );
				}
			}
		}

		if ( ui.zurBuehne ) {
			ui.zurBuehne = false;
			const buehne = wurzel.querySelector( '.fwb-buehne' );
			if ( buehne ) {
				const r = buehne.getBoundingClientRect();
				if ( r.top < 80 || r.bottom > window.innerHeight ) {
					buehne.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				}
			}
		}
	}

	/* ------------------------------ Steckbrief ------------------------------ */

	function steckbrief() {
		const s = dok.steckbrief;
		const felder = s.felder;
		const zeilen = felder.map( ( f, i ) =>
			el(
				'tr',
				{},
				el( 'td', {}, el( 'input', { type: 'text', class: 'widefat', value: f.label, 'aria-label': 'Bezeichnung', maxLength: 40, oninput: ( e ) => { f.label = e.target.value; gemerkt( false, 'feld-label-' + i ); } } ) ),
				el( 'td', {}, el( 'input', { type: 'text', class: 'widefat', value: f.wert, 'aria-label': 'Wert', maxLength: 120, oninput: ( e ) => { f.wert = e.target.value; gemerkt( false, 'feld-wert-' + i ); } } ) ),
				el(
					'td',
					{ class: 'fwb-mitte' },
					el( 'input', {
						type: 'checkbox',
						checked: !! f.eckdatum,
						'aria-label': 'Auf der Fahrzeugkarte zeigen',
						onchange: ( e ) => {
							if ( e.target.checked && felder.filter( ( x ) => x.eckdatum ).length >= 3 ) {
								e.target.checked = false;
								window.alert( 'Auf der Fahrzeugkarte passen höchstens drei Eckdaten.' );
								return;
							}
							f.eckdatum = e.target.checked;
							gemerkt( false );
						},
					} )
				),
				el( 'td', {}, ordnenKnoepfe( felder, i, () => gemerkt(), 'Feld' ), knopf( 'Entfernen', () => { felder.splice( i, 1 ); gemerkt(); }, 'button-link fwb-gefahr' ) )
			)
		);
		return el(
			'div',
			{ class: 'fwb-steckbrief' },
			el( 'p', { class: 'description', text: 'Der Steckbrief erscheint für Mitglieder auf der Fahrzeugkarte und unter „Info“. Er ist optional.' } ),
			el( 'h3', { text: 'Foto für die Fahrzeugkarte' } ),
			fotoFeld( s.foto_id, ( id ) => { s.foto_id = id; gemerkt(); } ),
			textFeld( 'Kurzbeschreibung', s.kurz, ( w ) => { s.kurz = w; }, { mehrzeilig: true, max: 1000 } ),
			el( 'h3', { text: 'Grunddaten' } ),
			el( 'p', { class: 'description', text: 'Frei benennbare Felder. Bis zu drei davon erscheinen als Eckdaten auf der Fahrzeugkarte.' } ),
			el(
				'div',
				{ class: 'fwb-tabelle-rahmen' },
				el(
					'table',
					{ class: 'widefat striped fwb-felder' },
					el( 'thead', {}, el( 'tr', {}, el( 'th', { text: 'Bezeichnung' } ), el( 'th', { text: 'Wert' } ), el( 'th', { text: 'Eckdatum' } ), el( 'th', { text: '' } ) ) ),
					el( 'tbody', {}, zeilen.length ? zeilen : el( 'tr', {}, el( 'td', { colSpan: 4, text: 'Noch keine Felder.' } ) ) )
				)
			),
			el(
				'p',
				{},
				knopf( 'Feld hinzufügen', () => { felder.push( { label: 'Neues Feld', wert: '', eckdatum: false } ); gemerkt(); } ),
				' ',
				knopf( 'Übliche Felder einfügen', () => {
					const da = new Set( felder.map( ( f ) => f.label ) );
					for ( const label of M.UEBLICHE_FELDER ) {
						if ( ! da.has( label ) ) {
							felder.push( { label, wert: '', eckdatum: false } );
						}
					}
					gemerkt();
				}, 'button button-secondary' )
			)
		);
	}

	/* ------------------------------ Ansichten ------------------------------ */

	function ansichten() {
		const vorschlaege = [ 'Fahrerseite', 'Beifahrerseite', 'Heck', 'Dach', 'Front' ];
		if ( ! dok.ansichten.find( ( a ) => a.id === ui.ansichtId ) ) {
			ui.ansichtId = dok.ansichten[ 0 ] ? dok.ansichten[ 0 ].id : null;
		}
		const neueAnsicht = () => {
			const vorhanden = new Set( dok.ansichten.map( ( a ) => a.name ) );
			const name = vorschlaege.find( ( v ) => ! vorhanden.has( v ) ) || 'Ansicht ' + ( dok.ansichten.length + 1 );
			const a = { id: M.neueId( 'a' ), name, foto_id: 0, skizze: '', bereiche: [] };
			dok.ansichten.push( a );
			ui.ansichtId = a.id;
			gemerkt();
		};
		const leiste = chipLeiste( {
			titel: 'Ansichten',
			eintraege: dok.ansichten.map( ( a ) => ( {
				id: a.id,
				titel: a.name,
				untertitel: a.bereiche.length + ' Fächer',
				warnung: a.foto_id || a.skizze ? null : 'noch kein Foto',
			} ) ),
			aktivId: ui.ansichtId,
			beiWahl: ( id ) => { ui.ansichtId = id; ui.auswahl = null; ui.modus = null; zeichnen(); },
			neuText: '+ Ansicht',
			beiNeu: neueAnsicht,
		} );

		const index = dok.ansichten.findIndex( ( a ) => a.id === ui.ansichtId );
		const a = dok.ansichten[ index ];
		if ( ! a ) {
			return el(
				'div',
				{},
				leiste,
				el(
					'div',
					{ class: 'fwb-leer' },
					el( 'p', { text: 'Außenansichten zeigen das Fahrzeug von außen (Fahrerseite, Beifahrerseite, Heck, Dach). Mitglieder tippen darauf ein Gerätefach an.' } ),
					dok.faecher.length
						? knopf( '+ Erste Ansicht anlegen', neueAnsicht, 'button button-primary' )
						: el( 'p', { class: 'notice notice-info inline', text: 'Lege zuerst unter „Fächer und Geräte“ die Fächer an. Danach markierst du sie hier auf dem Foto.' } )
				)
			);
		}

		const freieFaecher = dok.faecher.filter( ( f ) => ! a.bereiche.some( ( r ) => r.fach_id === f.id ) );
		const ausgewaehlt = a.bereiche.find( ( r ) => r.id === ui.auswahl );
		const fachVon = ( id ) => dok.faecher.find( ( f ) => f.id === id );
		const imModus = ui.modus && 'bereich' === ui.modus.art;

		let werkzeug;
		if ( imModus ) {
			const f = fachVon( ui.modus.fachId );
			werkzeug = el( 'p', { class: 'fwb-tipp' }, 'Ziehe auf dem Foto ein Rechteck um Fach ', el( 'strong', { text: f ? f.kuerzel : '?' } ), '. ', knopf( 'Abbrechen', () => { ui.modus = null; zeichnen(); }, 'button button-small' ) );
		} else if ( ! dok.faecher.length ) {
			werkzeug = el( 'p', { class: 'notice notice-info inline', text: 'Lege zuerst unter „Fächer und Geräte“ die Fächer an. Danach markierst du sie hier auf dem Foto.' } );
		} else if ( freieFaecher.length ) {
			werkzeug = el(
				'div',
				{ class: 'fwb-werkzeug' },
				el( 'span', { text: 'Fach auf diesem Foto markieren – antippen, dann Rechteck ziehen:' } ),
				freieFaecher.map( ( f ) =>
					knopf( f.kuerzel, () => { ui.modus = { art: 'bereich', fachId: f.id }; ui.auswahl = null; ui.zurBuehne = true; zeichnen(); }, 'button button-small fwb-fach-knopf', { title: f.name || f.kuerzel } )
				)
			);
		} else {
			werkzeug = el( 'p', { class: 'description', text: 'Alle Fächer sind auf diesem Foto markiert. Fächer, die man von hier nicht sieht, einfach weglassen.' } );
		}

		const buehne = markierer( {
			bild: bildDaten( a.foto_id, a.skizze, 3 ),
			rechtecke: a.bereiche.map( ( r ) => ( { ...r, text: fachVon( r.fach_id ) ? fachVon( r.fach_id ).kuerzel : '?' } ) ),
			auswahl: ui.auswahl,
			zeichnen: imModus,
			beiNeu: ( r ) => {
				const neu = { id: M.neueId( 'r' ), fach_id: ui.modus.fachId, ...r };
				a.bereiche.push( neu );
				ui.modus = null;
				ui.auswahl = neu.id;
				gemerkt();
			},
			beiAendern: ( id, r ) => { Object.assign( a.bereiche.find( ( x ) => x.id === id ), r ); gemerkt( false, 'lage:' + id ); },
			beiAuswahl: ( id ) => { ui.auswahl = id; zeichnen(); },
			beiLoeschen: ( id ) => { a.bereiche = a.bereiche.filter( ( x ) => x.id !== id ); ui.auswahl = null; gemerkt(); },
		} );

		const info = ausgewaehlt
			? el(
				'div',
				{ class: 'fwb-auswahl' },
				el( 'strong', { text: 'Ausgewählt: Fach ' + ( fachVon( ausgewaehlt.fach_id ) || { kuerzel: '?' } ).kuerzel } ),
				' ',
				knopf( 'Zum Fach', () => sprungZu( { reiter: 'faecher', fachId: ausgewaehlt.fach_id } ), 'button button-small' ),
				' ',
				knopf( 'Fläche entfernen', () => { a.bereiche = a.bereiche.filter( ( x ) => x.id !== ausgewaehlt.id ); ui.auswahl = null; gemerkt(); }, 'button-link fwb-gefahr' ),
				el( 'p', { class: 'description', text: 'Ziehen zum Verschieben, an den Ecken ziehen für die Größe, Pfeiltasten zum Feinjustieren, Entf zum Löschen.' } )
			)
			: null;

		return el(
			'div',
			{},
			leiste,
			el(
				'div',
				{ class: 'fwb-zeile' },
				textFeld( 'Name der Ansicht', a.name, ( w ) => { a.name = w; }, { max: 40, neuZeichnen: true } ),
				el( 'div', { class: 'fwb-zeile-rechts' }, ordnenKnoepfe( dok.ansichten, index, () => gemerkt(), 'Ansicht' ), knopf( 'Ansicht löschen', () => {
					if ( window.confirm( 'Ansicht „' + a.name + '“ löschen? Die Fächer selbst bleiben erhalten.' ) ) {
						dok.ansichten.splice( index, 1 );
						gemerkt();
					}
				}, 'button-link fwb-gefahr' ) )
			),
			fotoFeld( a.foto_id, ( id ) => { a.foto_id = id; a.skizze = ''; gemerkt(); }, { skizze: a.skizze } ),
			werkzeug,
			buehne,
			info
		);
	}

	/* ------------------------------ Fächer ------------------------------ */

	function erstesFach() {
		const f = M.neuesFach( dok );
		ui.fachId = f.id;
		ui.bildId = f.bilder[ 0 ].id;
		gemerkt();
	}

	function faecher() {
		if ( ! dok.faecher.find( ( f ) => f.id === ui.fachId ) ) {
			ui.fachId = dok.faecher[ 0 ] ? dok.faecher[ 0 ].id : null;
			ui.bildId = null;
		}

		if ( ! dok.faecher.length ) {
			return el(
				'div',
				{ class: 'fwb-leer' },
				el( 'h3', { text: 'So geht es los' } ),
				el(
					'ol',
					{ class: 'fwb-schritte' },
					el( 'li', {}, el( 'strong', { text: 'Fach anlegen' } ), ' – z. B. „G1“ für Geräteraum 1.' ),
					el( 'li', {}, el( 'strong', { text: 'Foto wählen' } ), ' – das geöffnete Fach. Ohne Foto geht es auch, dann zeigt der Editor eine Fläche.' ),
					el( 'li', {}, el( 'strong', { text: 'Geräte hinzufügen' } ), ' – Namen eintippen, vorhandenes Gerät wählen oder neu anlegen.' ),
					el( 'li', {}, el( 'strong', { text: 'Markieren' } ), ' – mit der Maus ein Rechteck um das Gerät auf dem Foto ziehen.' ),
					el( 'li', {}, el( 'strong', { text: 'Außenansichten' } ), ' – zum Schluss die Fächer auf den Fotos von außen markieren.' )
				),
				el( 'p', {}, knopf( '+ Erstes Fach anlegen', erstesFach, 'button button-primary button-hero' ) ),
				el( 'p', { class: 'description', text: 'Schwenkwand oder Auszug? Pro Zustand ein Bild („geschlossen“, „geöffnet“), verbunden durch einen Schalter. Das kommt später im Fach.' } )
			);
		}

		const leiste = chipLeiste( {
			titel: 'Fächer',
			eintraege: dok.faecher.map( ( f ) => {
				const offen = M.unmarkiertePlaetze( f ).length;
				return {
					id: f.id,
					titel: f.kuerzel,
					untertitel: f.plaetze.length + ' Ger.',
					warnung: offen ? offen + ' nicht markiert' : null,
				};
			} ),
			aktivId: ui.fachId,
			beiWahl: ( id ) => { ui.fachId = id; ui.bildId = null; ui.auswahl = null; ui.modus = null; zeichnen(); },
			neuText: '+ Fach',
			beiNeu: erstesFach,
		} );

		const index = dok.faecher.findIndex( ( f ) => f.id === ui.fachId );
		const fach = dok.faecher[ index ];
		if ( ! fach.bilder.find( ( b ) => b.id === ui.bildId ) ) {
			ui.bildId = fach.bilder[ 0 ].id;
		}

		return el(
			'div',
			{},
			leiste,
			el(
				'div',
				{ class: 'fwb-zeile' },
				textFeld( 'Kürzel', fach.kuerzel, ( w ) => { fach.kuerzel = w; }, { max: 10, breit: false, neuZeichnen: true } ),
				textFeld( 'Name (optional)', fach.name, ( w ) => { fach.name = w; }, { max: 80, neuZeichnen: true, platzhalter: 'z. B. Geräteraum 1, Fahrerseite vorn' } ),
				el( 'div', { class: 'fwb-zeile-rechts' }, ordnenKnoepfe( dok.faecher, index, () => gemerkt(), 'Fach' ), knopf( 'Fach löschen', () => {
					if ( window.confirm( 'Fach ' + fach.kuerzel + ' mit allen Bildern und Markierungen löschen? Die Geräte bleiben im Katalog.' ) ) {
						M.fachLoeschen( dok, fach.id );
						gemerkt();
					}
				}, 'button-link fwb-gefahr' ) )
			),
			el(
				'div',
				{ class: 'fwb-arbeit' },
				el( 'div', { class: 'fwb-arbeit-innen' }, bilderBereich( fach ), geraeteBereich( fach ) )
			)
		);
	}

	/** Gerät auf dem aktuellen Bild markieren (Zeichenmodus starten). */
	function markierenStarten( platzId ) {
		ui.modus = { art: 'geraet', platzId };
		ui.auswahl = null;
		ui.angebot = null;
		ui.zurBuehne = true;
		zeichnen();
	}

	function geraeteBereich( fach ) {
		const bild = fach.bilder.find( ( b ) => b.id === ui.bildId );
		const bildNummer = fach.bilder.indexOf( bild ) + 1;
		const mehrereBilder = fach.bilder.length > 1;
		const ausgewaehlteMarke = bild.markierungen.find( ( m ) => m.id === ui.auswahl );

		const karten = fach.plaetze.map( ( p, i ) => {
			const uebersicht = M.markierungsUebersicht( fach, p.id );
			const hier = bild.markierungen.filter( ( m ) => m.platz_id === p.id );
			const istModus = ui.modus && 'geraet' === ui.modus.art && ui.modus.platzId === p.id;
			const istAktiv = ausgewaehlteMarke && ausgewaehlteMarke.platz_id === p.id;

			let markiertText;
			if ( ! uebersicht.length ) {
				markiertText = el( 'span', { class: 'fwb-warnung', text: 'noch nicht markiert' } );
			} else {
				markiertText = el(
					'span',
					{ class: 'fwb-markiert' },
					'Markiert: ',
					uebersicht.map( ( u, n ) => [
						n ? ', ' : '',
						el( mehrereBilder ? 'button' : 'span', {
							type: mehrereBilder ? 'button' : null,
							class: mehrereBilder ? 'button-link' : null,
							text: ( mehrereBilder ? 'Bild ' + u.nummer : 'auf dem Bild' ) + ( u.anzahl > 1 ? ' (' + u.anzahl + '×)' : '' ),
							onclick: mehrereBilder ? () => { ui.bildId = u.bildId; ui.auswahl = null; zeichnen(); } : null,
						} ),
					] )
				);
			}

			return el(
				'li',
				{
					class: 'fwb-geraet' + ( istModus ? ' ist-modus' : '' ) + ( istAktiv ? ' ist-aktiv' : '' ),
					'data-gruppe': p.id,
				},
				el(
					'div',
					{ class: 'fwb-geraet-kopf' },
					el( 'button', {
						type: 'button',
						class: 'fwb-geraet-name',
						text: geraetName( p.geraet ),
						title: hier.length ? 'Auf dem Foto auswählen' : 'Auf diesem Bild noch nicht markiert',
						onclick: () => {
							if ( hier.length ) {
								const jetzt = hier.findIndex( ( m ) => m.id === ui.auswahl );
								ui.auswahl = hier[ ( jetzt + 1 ) % hier.length ].id;
								ui.modus = null;
								zeichnen();
							}
						},
					} ),
					markiertText
				),
				el(
					'div',
					{ class: 'fwb-geraet-felder' },
					el( 'label', {}, 'Menge ', el( 'input', { type: 'text', class: 'fwb-schmal', value: p.menge, maxLength: 30, placeholder: '1', oninput: ( e ) => { p.menge = e.target.value; gemerkt( false, 'menge:' + p.id ); } } ) ),
					el( 'label', { class: 'fwb-breit' }, 'Lage im Fach ', el( 'input', { type: 'text', value: p.lage, maxLength: 80, placeholder: 'z. B. Schwenkwand, Rückseite', oninput: ( e ) => { p.lage = e.target.value; gemerkt( false, 'lage:' + p.id ); } } ) )
				),
				el(
					'div',
					{ class: 'fwb-geraet-aktionen' },
					istModus
						? knopf( 'Abbrechen', () => { ui.modus = null; zeichnen(); }, 'button button-small' )
						: knopf( hier.length ? '+ Weitere Stelle markieren' : 'Auf Bild ' + ( mehrereBilder ? bildNummer + ' ' : '' ) + 'markieren', () => markierenStarten( p.id ), hier.length ? 'button button-small' : 'button button-primary button-small' ),
					ordnenKnoepfe( fach.plaetze, i, () => gemerkt(), 'Gerät' ),
					katalog[ p.geraet ] ? el( 'a', { href: katalog[ p.geraet ].bearbeiten, target: '_blank', class: 'fwb-klein', text: 'Beschreibung ↗' } ) : null,
					knopf( 'Entfernen', () => {
						if ( window.confirm( geraetName( p.geraet ) + ' aus ' + fach.kuerzel + ' entfernen (mit allen Markierungen)? Im Katalog bleibt es erhalten.' ) ) {
							M.platzLoeschen( fach, p.id );
							gemerkt();
						}
					}, 'button-link fwb-gefahr' )
				)
			);
		} );

		const suche = Katalog.geraeteSuche( {
			ausschliessen: new Set( fach.plaetze.map( ( p ) => p.geraet ) ),
			beiWahl: ( g ) => {
				katalog[ g.uid ] = { uid: g.uid, name: g.name, bearbeiten: g.bearbeiten };
				const platz = { id: M.neueId( 'p' ), geraet: g.uid, menge: '1', lage: '', notiz: '' };
				fach.plaetze.push( platz );
				ui.modus = { art: 'geraet', platzId: platz.id };
				ui.auswahl = null;
				ui.zurBuehne = true;
				gemerkt();
			},
		} );

		return el(
			'div',
			{ class: 'fwb-geraete' },
			el( 'h3', { text: 'Geräte in ' + fach.kuerzel + ' (' + fach.plaetze.length + ')' } ),
			el( 'div', { class: 'fwb-hinzufuegen' }, el( 'label', { class: 'screen-reader-text', text: 'Gerät hinzufügen' } ), suche ),
			el(
				'p',
				{ class: 'description' },
				'Namen eintippen, vorhandenes Gerät wählen oder „… neu im Katalog anlegen“. Danach ein Rechteck auf dem Foto ziehen. Liegt ein Gerät an mehreren Stellen, einfach weitere Stellen markieren. ',
				el( 'a', { href: daten.links.katalog, target: '_blank', text: 'Gerätekatalog ↗' } )
			),
			fach.plaetze.length ? el( 'ul', { class: 'fwb-geraete-liste' }, karten ) : el( 'p', { class: 'fwb-leer-klein', text: 'Noch keine Geräte in diesem Fach.' } )
		);
	}

	function bilderBereich( fach ) {
		const bildIndex = fach.bilder.findIndex( ( b ) => b.id === ui.bildId );
		const bild = fach.bilder[ bildIndex ];
		const platzVon = ( id ) => fach.plaetze.find( ( p ) => p.id === id );
		const ausgewaehlteMarke = bild.markierungen.find( ( m ) => m.id === ui.auswahl );
		const ausgewaehlterSchalter = bild.schalter.find( ( s ) => s.id === ui.auswahl );
		const andereBilder = fach.bilder.filter( ( b ) => b.id !== bild.id );

		const bildReiter = el(
			'div',
			{ class: 'fwb-bild-reiter' },
			fach.bilder.map( ( b, i ) =>
				el( 'button', {
					type: 'button',
					class: 'fwb-bild-knopf' + ( b.id === bild.id ? ' ist-aktiv' : '' ),
					text: ( i + 1 ) + '. ' + b.titel,
					onclick: () => { ui.bildId = b.id; ui.auswahl = null; if ( ui.modus && 'schalter' === ui.modus.art ) { ui.modus = null; } zeichnen(); },
				} )
			),
			knopf( '+ Weiteres Bild', () => {
				const neu = M.neuesBild( fach, bild );
				ui.bildId = neu.id;
				ui.auswahl = null;
				gemerkt( false );
				meldung( 'Neues Bild angelegt – alle Markierungen von „' + bild.titel + '“ wurden übernommen. Nur verschieben, was sich bewegt.' );
				zeichnen();
			}, 'button button-secondary button-small', { title: 'Für Schwenkwand oder Auszug: ein Bild pro Zustand. Übernimmt alle Markierungen.' } )
		);

		let werkzeug;
		if ( ui.modus && 'geraet' === ui.modus.art && platzVon( ui.modus.platzId ) ) {
			werkzeug = el( 'p', { class: 'fwb-tipp' }, 'Ziehe auf dem Foto ein Rechteck um: ', el( 'strong', { text: geraetName( platzVon( ui.modus.platzId ).geraet ) } ), ' ', knopf( 'Abbrechen', () => { ui.modus = null; zeichnen(); }, 'button button-small' ) );
		} else if ( ui.modus && 'schalter' === ui.modus.art ) {
			werkzeug = el(
				'div',
				{ class: 'fwb-tipp' },
				'Tippe auf die Stelle im Foto, an der der Schalter sitzen soll (z. B. auf die Schwenkwand). Er wechselt zu Bild: ',
				el(
					'select',
					{ 'aria-label': 'Ziel des Schalters', onchange: ( e ) => { ui.modus.zielBildId = e.target.value; } },
					andereBilder.map( ( b ) => el( 'option', { value: b.id, text: ( fach.bilder.indexOf( b ) + 1 ) + '. ' + b.titel, selected: b.id === ui.modus.zielBildId } ) )
				),
				' ',
				knopf( 'Abbrechen', () => { ui.modus = null; zeichnen(); }, 'button button-small' )
			);
		} else if ( andereBilder.length ) {
			werkzeug = el(
				'div',
				{ class: 'fwb-werkzeug' },
				el( 'span', { text: 'Schalter setzen:' } ),
				knopf( '↻ Schwenkwand', () => { ui.modus = { art: 'schalter', schalterArt: 'schwenk', zielBildId: andereBilder[ 0 ].id }; ui.auswahl = null; zeichnen(); }, 'button button-small', { title: 'Gebogener Pfeil: Mitglieder tippen darauf und sehen das andere Bild.' } ),
				knopf( '⇣ Auszug', () => { ui.modus = { art: 'schalter', schalterArt: 'auszug', zielBildId: andereBilder[ 0 ].id }; ui.auswahl = null; zeichnen(); }, 'button button-small', { title: 'Gerader Pfeil: Mitglieder tippen darauf und sehen das andere Bild.' } )
			);
		} else {
			werkzeug = null;
		}

		const symbol = ( s ) => {
			const richtung = M.schalterRichtung( fach, bild.id, s.ziel_bild_id );
			if ( 'schwenk' === s.art ) {
				return 'auf' === richtung ? '↻' : '↺';
			}
			return 'auf' === richtung ? '⇣' : '⇡';
		};

		const buehne = markierer( {
			bild: bildDaten( bild.foto_id, bild.skizze, 4 / 3 ),
			rechtecke: bild.markierungen.map( ( m ) => ( {
				...m,
				gruppe: m.platz_id,
				text: ( platzVon( m.platz_id ) ? geraetName( platzVon( m.platz_id ).geraet ) : '?' ) + ( m.notiz ? ' · ' + m.notiz : '' ),
			} ) ),
			punkte: bild.schalter.map( ( s ) => {
				const ziel = fach.bilder.find( ( b ) => b.id === s.ziel_bild_id );
				return { ...s, symbol: symbol( s ), text: ( 'schwenk' === s.art ? 'Schwenkwand' : 'Auszug' ) + ' → ' + ( ziel ? ziel.titel : '?' ) };
			} ),
			auswahl: ui.auswahl,
			zeichnen: !! ( ui.modus && 'geraet' === ui.modus.art ),
			punktSetzen: !! ( ui.modus && 'schalter' === ui.modus.art ),
			beiNeu: ( r ) => {
				const neu = { id: M.neueId( 'm' ), platz_id: ui.modus.platzId, ...r, notiz: '' };
				bild.markierungen.push( neu );
				ui.modus = null;
				ui.auswahl = neu.id;
				ui.angebot = M.bilderOhneGeraet( fach, bild.id, neu.platz_id ).length ? neu.id : null;
				gemerkt();
			},
			beiPunktNeu: ( p ) => {
				const neu = { id: M.neueId( 's' ), art: ui.modus.schalterArt, ziel_bild_id: ui.modus.zielBildId, ...p };
				bild.schalter.push( neu );
				ui.modus = null;
				ui.auswahl = neu.id;
				gemerkt();
			},
			beiAendern: ( id, r ) => { Object.assign( bild.markierungen.find( ( m ) => m.id === id ), r ); gemerkt( false, 'lage:' + id ); },
			beiPunktAendern: ( id, p ) => { Object.assign( bild.schalter.find( ( s ) => s.id === id ), p ); gemerkt( false, 'lage:' + id ); },
			beiAuswahl: ( id ) => { ui.auswahl = id; zeichnen(); },
			beiLoeschen: ( id ) => {
				bild.markierungen = bild.markierungen.filter( ( m ) => m.id !== id );
				bild.schalter = bild.schalter.filter( ( s ) => s.id !== id );
				ui.auswahl = null;
				gemerkt();
			},
		} );

		let auswahlInfo = null;
		if ( ausgewaehlteMarke ) {
			const m = ausgewaehlteMarke;
			const fehlendeBilder = M.bilderOhneGeraet( fach, bild.id, m.platz_id );
			const uebertragen = () => {
				const anzahl = M.markierungUebertragen( fach, bild.id, m.id );
				ui.angebot = null;
				gemerkt( false );
				meldung( 'Auf ' + anzahl + ( 1 === anzahl ? ' weiteres Bild' : ' weitere Bilder' ) + ' übertragen. Dort bei Bedarf verschieben.' );
				zeichnen();
			};
			const notizId = 'fwb-marke-notiz';
			auswahlInfo = el(
				'div',
				{ class: 'fwb-auswahl' },
				ui.angebot === m.id
					? el(
						'p',
						{ class: 'fwb-frage' },
						'Liegt ', el( 'strong', { text: geraetName( platzVon( m.platz_id ).geraet ) } ), ' auf ',
						1 === fehlendeBilder.length ? 'dem anderen Bild' : 'den anderen ' + fehlendeBilder.length + ' Bildern',
						' an derselben Stelle? ',
						knopf( 'Ja, übertragen', uebertragen, 'button button-primary button-small' ),
						' ',
						knopf( 'Nein', () => { ui.angebot = null; zeichnen(); }, 'button button-small' )
					)
					: null,
				el(
					'div',
					{ class: 'fwb-auswahl-zeile' },
					el( 'label', { for: 'fwb-marke-geraet', text: 'Markierung gehört zu ' } ),
					el(
						'select',
						{ id: 'fwb-marke-geraet', onchange: ( e ) => { m.platz_id = e.target.value; gemerkt(); } },
						fach.plaetze.map( ( p ) => el( 'option', { value: p.id, text: geraetName( p.geraet ), selected: p.id === m.platz_id } ) )
					),
					el( 'label', { for: notizId, text: ' Notiz zu dieser Stelle ' } ),
					el( 'input', {
						id: notizId,
						type: 'text',
						class: 'fwb-notiz',
						value: m.notiz || '',
						maxLength: 60,
						placeholder: 'z. B. oben links',
						oninput: ( e ) => { m.notiz = e.target.value; gemerkt( false, 'notiz:' + m.id ); },
						onchange: () => zeichnen(),
						onkeydown: ( e ) => { if ( 'Enter' === e.key ) { e.preventDefault(); zeichnen(); } },
					} )
				),
				el(
					'div',
					{ class: 'fwb-auswahl-zeile' },
					fehlendeBilder.length && ui.angebot !== m.id
						? knopf( 'Auf ' + ( 1 === fehlendeBilder.length ? 'Bild ' + ( fach.bilder.indexOf( fehlendeBilder[ 0 ] ) + 1 ) : fehlendeBilder.length + ' andere Bilder' ) + ' übertragen', uebertragen, 'button button-small', { title: 'Kopiert die Markierung an dieselbe Stelle auf die Bilder, auf denen das Gerät noch fehlt.' } )
						: null,
					knopf( 'Markierung entfernen', () => { bild.markierungen = bild.markierungen.filter( ( x ) => x.id !== m.id ); ui.auswahl = null; gemerkt(); }, 'button-link fwb-gefahr' )
				),
				el( 'p', { class: 'description', text: 'Ziehen zum Verschieben, an den Ecken ziehen für die Größe, Pfeiltasten zum Feinjustieren, Entf zum Löschen.' } )
			);
		} else if ( ausgewaehlterSchalter ) {
			const s = ausgewaehlterSchalter;
			auswahlInfo = el(
				'div',
				{ class: 'fwb-auswahl' },
				el( 'label', { for: 'fwb-schalter-art', text: 'Schalter: ' } ),
				el(
					'select',
					{ id: 'fwb-schalter-art', onchange: ( e ) => { s.art = e.target.value; gemerkt(); } },
					el( 'option', { value: 'schwenk', text: 'Schwenkwand (gebogener Pfeil)', selected: 'schwenk' === s.art } ),
					el( 'option', { value: 'auszug', text: 'Auszug (gerader Pfeil)', selected: 'auszug' === s.art } )
				),
				el( 'label', { for: 'fwb-schalter-ziel', text: ' zeigt Bild: ' } ),
				el(
					'select',
					{ id: 'fwb-schalter-ziel', onchange: ( e ) => { s.ziel_bild_id = e.target.value; gemerkt(); } },
					andereBilder.map( ( b ) => el( 'option', { value: b.id, text: ( fach.bilder.indexOf( b ) + 1 ) + '. ' + b.titel, selected: b.id === s.ziel_bild_id } ) )
				),
				' ',
				knopf( 'Schalter entfernen', () => { bild.schalter = bild.schalter.filter( ( x ) => x.id !== s.id ); ui.auswahl = null; gemerkt(); }, 'button-link fwb-gefahr' )
			);
		}

		return el(
			'div',
			{ class: 'fwb-bilder' },
			el( 'h3', {}, 'Bilder von ' + fach.kuerzel, el( 'span', { class: 'fwb-h-zusatz', text: fach.bilder.length > 1 ? ' – ein Bild pro Zustand' : '' } ) ),
			bildReiter,
			fach.bilder.length < 2 ? el( 'p', { class: 'description', text: 'Schwenkwand oder Auszug? „+ Weiteres Bild“ legt ein zweites Bild an (z. B. „geöffnet“) und übernimmt alle Markierungen. Dann einen Schalter setzen.' } ) : null,
			el(
				'div',
				{ class: 'fwb-zeile' },
				textFeld( 'Titel des Bildes', bild.titel, ( w ) => { bild.titel = w; }, { max: 60, neuZeichnen: true, platzhalter: 'z. B. Schwenkwand geöffnet' } ),
				el(
					'div',
					{ class: 'fwb-zeile-rechts' },
					fach.bilder.length > 1 ? ordnenKnoepfe( fach.bilder, bildIndex, () => gemerkt(), 'Bild' ) : null,
					fach.bilder.length > 1
						? knopf( 'Bild löschen', () => {
							if ( window.confirm( 'Bild „' + bild.titel + '“ mit seinen Markierungen löschen?' ) ) {
								M.bildLoeschen( fach, bild.id );
								ui.bildId = null;
								gemerkt();
							}
						}, 'button-link fwb-gefahr' )
						: null
				)
			),
			fotoFeld( bild.foto_id, ( id ) => { bild.foto_id = id; bild.skizze = ''; gemerkt(); }, { skizze: bild.skizze } ),
			werkzeug,
			buehne,
			auswahlInfo
		);
	}

	/* ------------------------------ Start ------------------------------ */

	// Liste und Foto heben gemeinsam hervor: Maus auf ein Gerät → seine Rechtecke leuchten, und umgekehrt.
	function hervorheben( gruppe ) {
		for ( const e of wurzel.querySelectorAll( '.ist-hervorgehoben' ) ) {
			e.classList.remove( 'ist-hervorgehoben' );
		}
		if ( gruppe ) {
			for ( const e of wurzel.querySelectorAll( '[data-gruppe="' + CSS.escape( gruppe ) + '"]' ) ) {
				e.classList.add( 'ist-hervorgehoben' );
			}
		}
	}
	wurzel.addEventListener( 'pointerover', ( e ) => {
		const ziel = e.target.closest( '[data-gruppe]' );
		hervorheben( ziel ? ziel.dataset.gruppe : null );
	} );
	wurzel.addEventListener( 'pointerleave', () => hervorheben( null ) );

	// Strg+Z / Strg+Umschalt+Z (am Mac Cmd) – außer beim Tippen in einem Feld.
	document.addEventListener( 'keydown', ( e ) => {
		if ( ! ( e.ctrlKey || e.metaKey ) || 'z' !== e.key.toLowerCase() ) {
			return;
		}
		const t = e.target;
		if ( t && ( 'INPUT' === t.tagName || 'TEXTAREA' === t.tagName || 'SELECT' === t.tagName || t.isContentEditable ) ) {
			return;
		}
		e.preventDefault();
		standLaden( e.shiftKey ? M.verlaufVor( verlauf ) : M.verlaufZurueck( verlauf ) );
	} );

	// Frühere Stände aus der Seitenleiste laden.
	document.addEventListener( 'click', ( e ) => {
		const knopfVersion = e.target.closest( '.fwb-version-laden' );
		if ( ! knopfVersion ) {
			return;
		}
		const json = daten.versionen[ Number( knopfVersion.dataset.version ) ];
		if ( ! json || ! window.confirm( 'Diesen früheren Stand in den Editor laden? Er wird erst mit „Speichern“ gespeichert (und lässt sich mit „Rückgängig“ zurücknehmen).' ) ) {
			return;
		}
		dok = M.vervollstaendigen( JSON.parse( json ) );
		ui.auswahl = null;
		ui.modus = null;
		gemerkt();
	} );

	const formular = document.getElementById( 'post' );
	if ( formular ) {
		formular.addEventListener( 'submit', () => {
			feld.value = JSON.stringify( dok );
			absenden = true;
		} );
	}
	window.addEventListener( 'beforeunload', ( e ) => {
		if ( geaendert && ! absenden ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	if ( ! dok.faecher.length && ( dok.steckbrief.kurz || dok.steckbrief.felder.length ) ) {
		ui.reiter = 'steckbrief';
	}
	zeichnen();
}
