/**
 * Backend-Editor "Beladung": Steckbrief, Ansichten und Fächer.
 *
 * Der ganze Stand liegt in "dok". Jede Änderung schreibt das Dokument als
 * JSON in das versteckte Feld; gespeichert wird mit dem normalen
 * "Aktualisieren"/"Veröffentlichen" von WordPress.
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
		reiter: dok.faecher.length ? 'faecher' : 'steckbrief',
		ansichtId: null,
		fachId: null,
		bildId: null,
		auswahl: null,
		modus: null, // { art: 'bereich', fachId } | { art: 'geraet', platzId } | { art: 'schalter', schalterArt, zielBildId }
	};
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
			} else if ( k in e && 'list' !== k ) {
				e[ k ] = v;
			} else {
				e.setAttribute( k, true === v ? '' : v );
			}
		}
		for ( const kind of kinder.flat() ) {
			if ( null !== kind && undefined !== kind && false !== kind ) {
				e.append( kind instanceof Node ? kind : document.createTextNode( String( kind ) ) );
			}
		}
		return e;
	}

	function knopf( text, beiKlick, klasse = 'button' ) {
		return el( 'button', { type: 'button', class: klasse, text, onclick: beiKlick } );
	}

	function textFeld( label, wert, beiEingabe, { mehrzeilig = false, max = 200, breit = true, neuZeichnen = false } = {} ) {
		const id = 'fwb-feld-' + Math.random().toString( 36 ).slice( 2 );
		const eingabe = el( mehrzeilig ? 'textarea' : 'input', {
			id,
			class: breit ? 'widefat' : 'regular-text',
			value: wert || '',
			maxLength: max,
			rows: mehrzeilig ? 4 : null,
			type: mehrzeilig ? null : 'text',
			oninput: ( e ) => {
				beiEingabe( e.target.value );
				gemerkt( false );
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

	function gemerkt( neuZeichnen = true ) {
		feld.value = JSON.stringify( dok );
		geaendert = true;
		if ( neuZeichnen ) {
			zeichnen();
		} else {
			statusAktualisieren();
		}
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
			title: 'Foto auswählen',
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

	function fotoFeld( fotoId, beiAenderung ) {
		const foto = fotoId ? fotos[ fotoId ] : null;
		return el(
			'div',
			{ class: 'fwb-foto-feld' },
			foto ? el( 'img', { src: foto.url, alt: '', class: 'fwb-foto-vorschau' } ) : el( 'span', { class: 'description', text: 'Kein Foto gewählt.' } ),
			knopf( foto ? 'Foto ändern' : 'Foto aus Mediathek wählen', () => fotoWaehlen( ( id ) => beiAenderung( id ) ) ),
			foto ? knopf( 'Foto entfernen', () => beiAenderung( 0 ), 'button-link fwb-gefahr' ) : null
		);
	}

	function geraetName( uid ) {
		return katalog[ uid ] ? katalog[ uid ].name : '(unbekanntes Gerät)';
	}

	function auswahlListe( { titel, eintraege, aktivId, beiWahl, beiNeu, neuText } ) {
		return el(
			'div',
			{ class: 'fwb-liste' },
			el( 'h3', { text: titel } ),
			el(
				'ul',
				{},
				eintraege.map( ( e ) =>
					el(
						'li',
						{},
						el(
							'button',
							{
								type: 'button',
								class: 'fwb-liste-eintrag' + ( e.id === aktivId ? ' ist-aktiv' : '' ),
								'aria-current': e.id === aktivId ? 'true' : null,
								onclick: () => beiWahl( e.id ),
							},
							el( 'strong', { text: e.titel } ),
							e.untertitel ? el( 'span', { text: e.untertitel } ) : null,
							e.warnung ? el( 'span', { class: 'fwb-warnung', text: e.warnung } ) : null
						)
					)
				)
			),
			knopf( neuText, beiNeu, 'button button-secondary fwb-neu' )
		);
	}

	function ordnenKnoepfe( liste, index, beiFertig ) {
		return el(
			'span',
			{ class: 'fwb-ordnen' },
			knopf( '↑', () => M.verschiebeInListe( liste, index, -1 ) && beiFertig(), 'button button-small' ),
			knopf( '↓', () => M.verschiebeInListe( liste, index, 1 ) && beiFertig(), 'button button-small' )
		);
	}

	/* ------------------------------ Aufbau ------------------------------ */

	function statusAktualisieren() {
		const status = wurzel.querySelector( '.fwb-status' );
		if ( status ) {
			status.textContent = geaendert ? 'Nicht gespeicherte Änderungen – rechts auf „Aktualisieren“ bzw. „Speichern“ klicken.' : '';
		}
	}

	function zeichnen() {
		const reiter = [
			[ 'steckbrief', 'Steckbrief' ],
			[ 'ansichten', 'Außenansichten' ],
			[ 'faecher', 'Fächer und Geräte' ],
		];
		const kopf = el(
			'div',
			{ class: 'fwb-kopf' },
			el(
				'div',
				{ class: 'fwb-reiter', role: 'tablist' },
				reiter.map( ( [ id, text ] ) =>
					el( 'button', {
						type: 'button',
						role: 'tab',
						class: 'fwb-reiter-knopf' + ( ui.reiter === id ? ' ist-aktiv' : '' ),
						'aria-selected': ui.reiter === id ? 'true' : 'false',
						text,
						onclick: () => {
							ui.reiter = id;
							ui.auswahl = null;
							ui.modus = null;
							zeichnen();
						},
					} )
				)
			),
			el( 'p', { class: 'fwb-status', 'aria-live': 'polite' } )
		);
		let inhalt;
		if ( 'steckbrief' === ui.reiter ) {
			inhalt = steckbrief();
		} else if ( 'ansichten' === ui.reiter ) {
			inhalt = ansichten();
		} else {
			inhalt = faecher();
		}
		wurzel.replaceChildren( kopf, el( 'div', { class: 'fwb-inhalt', role: 'tabpanel' }, inhalt ) );
		statusAktualisieren();
	}

	/* ------------------------------ Steckbrief ------------------------------ */

	function steckbrief() {
		const s = dok.steckbrief;
		const felder = s.felder;
		const zeilen = felder.map( ( f, i ) =>
			el(
				'tr',
				{},
				el( 'td', {}, el( 'input', { type: 'text', class: 'widefat', value: f.label, 'aria-label': 'Bezeichnung', maxLength: 40, oninput: ( e ) => { f.label = e.target.value; gemerkt( false ); } } ) ),
				el( 'td', {}, el( 'input', { type: 'text', class: 'widefat', value: f.wert, 'aria-label': 'Wert', maxLength: 120, oninput: ( e ) => { f.wert = e.target.value; gemerkt( false ); } } ) ),
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
				el( 'td', {}, ordnenKnoepfe( felder, i, () => gemerkt() ), knopf( 'Entfernen', () => { felder.splice( i, 1 ); gemerkt(); }, 'button-link fwb-gefahr' ) )
			)
		);
		return el(
			'div',
			{ class: 'fwb-steckbrief' },
			el( 'h3', { text: 'Foto für die Fahrzeugkarte' } ),
			fotoFeld( s.foto_id, ( id ) => { s.foto_id = id; gemerkt(); } ),
			textFeld( 'Kurzbeschreibung', s.kurz, ( w ) => { s.kurz = w; }, { mehrzeilig: true, max: 1000 } ),
			el( 'h3', { text: 'Grunddaten' } ),
			el( 'p', { class: 'description', text: 'Frei benennbare Felder. Bis zu drei davon erscheinen als Eckdaten auf der Fahrzeugkarte.' } ),
			el(
				'table',
				{ class: 'widefat striped fwb-felder' },
				el( 'thead', {}, el( 'tr', {}, el( 'th', { text: 'Bezeichnung' } ), el( 'th', { text: 'Wert' } ), el( 'th', { text: 'Eckdatum' } ), el( 'th', { text: '' } ) ) ),
				el( 'tbody', {}, zeilen.length ? zeilen : el( 'tr', {}, el( 'td', { colSpan: 4, text: 'Noch keine Felder.' } ) ) )
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
		const liste = auswahlListe( {
			titel: 'Ansichten',
			eintraege: dok.ansichten.map( ( a ) => ( { id: a.id, titel: a.name, untertitel: a.bereiche.length + ' Fächer markiert' } ) ),
			aktivId: ui.ansichtId,
			beiWahl: ( id ) => { ui.ansichtId = id; ui.auswahl = null; ui.modus = null; zeichnen(); },
			neuText: '+ Ansicht hinzufügen',
			beiNeu: () => {
				const vorhanden = new Set( dok.ansichten.map( ( a ) => a.name ) );
				const name = vorschlaege.find( ( v ) => ! vorhanden.has( v ) ) || 'Ansicht ' + ( dok.ansichten.length + 1 );
				const a = { id: M.neueId( 'a' ), name, foto_id: 0, skizze: '', bereiche: [] };
				dok.ansichten.push( a );
				ui.ansichtId = a.id;
				gemerkt();
			},
		} );

		const index = dok.ansichten.findIndex( ( a ) => a.id === ui.ansichtId );
		const a = dok.ansichten[ index ];
		if ( ! a ) {
			return el( 'div', { class: 'fwb-spalten' }, liste, el( 'div', { class: 'fwb-detail' }, el( 'p', { text: 'Lege eine Ansicht an, z. B. „Fahrerseite“, und wähle ein Außenfoto. Dann markierst du darauf die Gerätefächer.' } ) ) );
		}

		const freieFaecher = dok.faecher.filter( ( f ) => ! a.bereiche.some( ( r ) => r.fach_id === f.id ) );
		const ausgewaehlt = a.bereiche.find( ( r ) => r.id === ui.auswahl );
		const fachVon = ( id ) => dok.faecher.find( ( f ) => f.id === id );

		const werkzeug = dok.faecher.length
			? el(
				'div',
				{ class: 'fwb-werkzeug' },
				el( 'label', { for: 'fwb-fach-platzieren', text: 'Fach auf dem Foto markieren: ' } ),
				el(
					'select',
					{
						id: 'fwb-fach-platzieren',
						onchange: ( e ) => {
							ui.modus = e.target.value ? { art: 'bereich', fachId: e.target.value } : null;
							ui.auswahl = null;
							zeichnen();
						},
					},
					el( 'option', { value: '', text: freieFaecher.length ? '– Fach wählen –' : 'alle Fächer sind markiert' } ),
					freieFaecher.map( ( f ) => el( 'option', { value: f.id, text: f.kuerzel + ( f.name ? ' – ' + f.name : '' ), selected: ui.modus && ui.modus.fachId === f.id } ) )
				),
				ui.modus && 'bereich' === ui.modus.art ? el( 'span', { class: 'fwb-tipp', text: ' Jetzt auf dem Foto ein Rechteck um das Fach ziehen.' } ) : null
			)
			: el( 'p', { class: 'notice notice-info inline', text: 'Lege zuerst unter „Fächer und Geräte“ die Fächer an. Danach markierst du sie hier auf dem Foto.' } );

		const buehne = markierer( {
			bild: bildDaten( a.foto_id, a.skizze, 3 ),
			rechtecke: a.bereiche.map( ( r ) => ( { ...r, text: fachVon( r.fach_id ) ? fachVon( r.fach_id ).kuerzel : '?' } ) ),
			auswahl: ui.auswahl,
			zeichnen: !! ( ui.modus && 'bereich' === ui.modus.art ),
			beiNeu: ( r ) => {
				const neu = { id: M.neueId( 'r' ), fach_id: ui.modus.fachId, ...r };
				a.bereiche.push( neu );
				ui.modus = null;
				ui.auswahl = neu.id;
				gemerkt();
			},
			beiAendern: ( id, r ) => { Object.assign( a.bereiche.find( ( x ) => x.id === id ), r ); gemerkt( false ); },
			beiAuswahl: ( id ) => { ui.auswahl = id; zeichnen(); },
			beiLoeschen: ( id ) => { a.bereiche = a.bereiche.filter( ( x ) => x.id !== id ); ui.auswahl = null; gemerkt(); },
		} );

		const info = ausgewaehlt
			? el(
				'div',
				{ class: 'fwb-auswahl' },
				el( 'strong', { text: 'Ausgewählt: ' + ( fachVon( ausgewaehlt.fach_id ) || { kuerzel: '?' } ).kuerzel } ),
				' ',
				knopf( 'Fläche entfernen', () => { a.bereiche = a.bereiche.filter( ( x ) => x.id !== ausgewaehlt.id ); ui.auswahl = null; gemerkt(); }, 'button-link fwb-gefahr' ),
				el( 'span', { class: 'description', text: ' Ziehen zum Verschieben, Ecken zum Vergrößern, Pfeiltasten zum Feinjustieren.' } )
			)
			: null;

		return el(
			'div',
			{ class: 'fwb-spalten' },
			liste,
			el(
				'div',
				{ class: 'fwb-detail' },
				el(
					'div',
					{ class: 'fwb-zeile' },
					textFeld( 'Name der Ansicht', a.name, ( w ) => { a.name = w; }, { max: 40, neuZeichnen: true } ),
					el( 'div', { class: 'fwb-zeile-rechts' }, ordnenKnoepfe( dok.ansichten, index, () => gemerkt() ), knopf( 'Ansicht löschen', () => {
						if ( window.confirm( 'Ansicht „' + a.name + '“ löschen? Die Fächer selbst bleiben erhalten.' ) ) {
							dok.ansichten.splice( index, 1 );
							gemerkt();
						}
					}, 'button-link fwb-gefahr' ) )
				),
				fotoFeld( a.foto_id, ( id ) => { a.foto_id = id; a.skizze = ''; gemerkt(); } ),
				werkzeug,
				buehne,
				info
			)
		);
	}

	/* ------------------------------ Fächer ------------------------------ */

	function faecher() {
		if ( ! dok.faecher.find( ( f ) => f.id === ui.fachId ) ) {
			ui.fachId = dok.faecher[ 0 ] ? dok.faecher[ 0 ].id : null;
			ui.bildId = null;
		}
		const liste = auswahlListe( {
			titel: 'Fächer',
			eintraege: dok.faecher.map( ( f ) => {
				const offen = M.unmarkiertePlaetze( f ).length;
				return {
					id: f.id,
					titel: f.kuerzel,
					untertitel: ( f.name || '' ) + ' · ' + f.plaetze.length + ' Geräte',
					warnung: offen ? offen + ' nicht markiert' : null,
				};
			} ),
			aktivId: ui.fachId,
			beiWahl: ( id ) => { ui.fachId = id; ui.bildId = null; ui.auswahl = null; ui.modus = null; zeichnen(); },
			neuText: '+ Fach hinzufügen',
			beiNeu: () => {
				const f = M.neuesFach( dok );
				ui.fachId = f.id;
				ui.bildId = f.bilder[ 0 ].id;
				gemerkt();
			},
		} );

		const index = dok.faecher.findIndex( ( f ) => f.id === ui.fachId );
		const fach = dok.faecher[ index ];
		if ( ! fach ) {
			return el( 'div', { class: 'fwb-spalten' }, liste, el( 'div', { class: 'fwb-detail' }, el( 'p', { text: 'Lege das erste Fach an, z. B. „G1“.' } ) ) );
		}
		if ( ! fach.bilder.find( ( b ) => b.id === ui.bildId ) ) {
			ui.bildId = fach.bilder[ 0 ].id;
		}

		return el(
			'div',
			{ class: 'fwb-spalten' },
			liste,
			el(
				'div',
				{ class: 'fwb-detail' },
				el(
					'div',
					{ class: 'fwb-zeile' },
					textFeld( 'Kürzel', fach.kuerzel, ( w ) => { fach.kuerzel = w; }, { max: 10, breit: false, neuZeichnen: true } ),
					textFeld( 'Name (optional)', fach.name, ( w ) => { fach.name = w; }, { max: 80, neuZeichnen: true } ),
					el( 'div', { class: 'fwb-zeile-rechts' }, ordnenKnoepfe( dok.faecher, index, () => gemerkt() ), knopf( 'Fach löschen', () => {
						if ( window.confirm( 'Fach ' + fach.kuerzel + ' mit allen Bildern und Markierungen löschen? Die Geräte bleiben im Katalog.' ) ) {
							M.fachLoeschen( dok, fach.id );
							gemerkt();
						}
					}, 'button-link fwb-gefahr' ) )
				),
				plaetzeTabelle( fach ),
				bilderBereich( fach )
			)
		);
	}

	function plaetzeTabelle( fach ) {
		const markiertAuf = ( platzId ) =>
			fach.bilder
				.map( ( b, i ) => ( b.markierungen.some( ( m ) => m.platz_id === platzId ) ? i + 1 : null ) )
				.filter( Boolean );

		const zeilen = fach.plaetze.map( ( p, i ) => {
			const auf = markiertAuf( p.id );
			const istModus = ui.modus && 'geraet' === ui.modus.art && ui.modus.platzId === p.id;
			return el(
				'tr',
				{ class: istModus ? 'ist-aktiv' : '' },
				el(
					'td',
					{},
					el( 'strong', { text: geraetName( p.geraet ) } ),
					katalog[ p.geraet ] ? el( 'a', { href: katalog[ p.geraet ].bearbeiten, target: '_blank', class: 'fwb-klein', text: ' Beschreibung bearbeiten' } ) : null
				),
				el( 'td', {}, el( 'input', { type: 'text', class: 'fwb-schmal', value: p.menge, maxLength: 30, 'aria-label': 'Menge', placeholder: 'z. B. 2', oninput: ( e ) => { p.menge = e.target.value; gemerkt( false ); } } ) ),
				el( 'td', {}, el( 'input', { type: 'text', class: 'widefat', value: p.lage, maxLength: 80, 'aria-label': 'Lage im Fach', placeholder: 'z. B. Schwenkwand, Rückseite', oninput: ( e ) => { p.lage = e.target.value; gemerkt( false ); } } ) ),
				el( 'td', {}, auf.length ? el( 'span', { text: 'Bild ' + auf.join( ', ' ) } ) : el( 'span', { class: 'fwb-warnung', text: 'noch nicht markiert' } ) ),
				el(
					'td',
					{ class: 'fwb-aktionen' },
					knopf( istModus ? 'Abbrechen' : 'Auf Bild markieren', () => {
						ui.modus = istModus ? null : { art: 'geraet', platzId: p.id };
						ui.auswahl = null;
						zeichnen();
					}, istModus ? 'button' : 'button button-primary button-small' ),
					ordnenKnoepfe( fach.plaetze, i, () => gemerkt() ),
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
				gemerkt();
			},
		} );

		return el(
			'div',
			{ class: 'fwb-plaetze' },
			el( 'h3', { text: 'Geräte in ' + fach.kuerzel } ),
			el(
				'div',
				{ class: 'fwb-tabelle-rahmen' },
				el(
					'table',
					{ class: 'widefat striped' },
					el( 'thead', {}, el( 'tr', {}, el( 'th', { text: 'Gerät' } ), el( 'th', { text: 'Menge' } ), el( 'th', { text: 'Lage im Fach' } ), el( 'th', { text: 'Markiert' } ), el( 'th', { text: '' } ) ) ),
					el( 'tbody', {}, zeilen.length ? zeilen : el( 'tr', {}, el( 'td', { colSpan: 5, text: 'Noch keine Geräte in diesem Fach.' } ) ) )
				)
			),
			el( 'div', { class: 'fwb-hinzufuegen' }, el( 'strong', { text: 'Gerät hinzufügen: ' } ), suche ),
			el(
				'p',
				{ class: 'description' },
				'Namen eintippen, dann ein vorhandenes Gerät antippen oder mit „… neu im Katalog anlegen“ ein neues anlegen. Danach auf dem Foto ein Rechteck um das Gerät ziehen. ',
				el( 'a', { href: daten.links.katalog, target: '_blank', text: 'Beschreibungen im Gerätekatalog bearbeiten' } )
			)
		);
	}

	function bilderBereich( fach ) {
		const bildIndex = fach.bilder.findIndex( ( b ) => b.id === ui.bildId );
		const bild = fach.bilder[ bildIndex ];
		const platzVon = ( id ) => fach.plaetze.find( ( p ) => p.id === id );
		const ausgewaehlteMarke = bild.markierungen.find( ( m ) => m.id === ui.auswahl );
		const ausgewaehlterSchalter = bild.schalter.find( ( s ) => s.id === ui.auswahl );

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
				gemerkt();
			}, 'button button-secondary' )
		);

		const andereBilder = fach.bilder.filter( ( b ) => b.id !== bild.id );
		let werkzeug;
		if ( ui.modus && 'geraet' === ui.modus.art && platzVon( ui.modus.platzId ) ) {
			werkzeug = el( 'p', { class: 'fwb-tipp' }, 'Ziehe auf dem Foto ein Rechteck um: ', el( 'strong', { text: geraetName( platzVon( ui.modus.platzId ).geraet ) } ), ' ', knopf( 'Abbrechen', () => { ui.modus = null; zeichnen(); }, 'button button-small' ) );
		} else if ( ui.modus && 'schalter' === ui.modus.art ) {
			werkzeug = el( 'p', { class: 'fwb-tipp' }, 'Tippe auf die Stelle im Foto, an der der Schalter sitzen soll (z. B. auf die Schwenkwand). ', knopf( 'Abbrechen', () => { ui.modus = null; zeichnen(); }, 'button button-small' ) );
		} else {
			werkzeug = el(
				'div',
				{ class: 'fwb-werkzeug' },
				andereBilder.length
					? knopf( 'Schalter für Schwenkwand setzen', () => { ui.modus = { art: 'schalter', schalterArt: 'schwenk', zielBildId: andereBilder[ 0 ].id }; ui.auswahl = null; zeichnen(); }, 'button button-small' )
					: null,
				andereBilder.length
					? knopf( 'Schalter für Auszug setzen', () => { ui.modus = { art: 'schalter', schalterArt: 'auszug', zielBildId: andereBilder[ 0 ].id }; ui.auswahl = null; zeichnen(); }, 'button button-small' )
					: el( 'span', { class: 'description', text: 'Für Schwenkwand oder Auszug: „+ Weiteres Bild“ anlegen (z. B. Schwenkwand geöffnet). Danach setzt du hier den Schalter.' } )
			);
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
				text: platzVon( m.platz_id ) ? geraetName( platzVon( m.platz_id ).geraet ) : '?',
			} ) ),
			punkte: bild.schalter.map( ( s ) => {
				const ziel = fach.bilder.find( ( b ) => b.id === s.ziel_bild_id );
				return { ...s, symbol: symbol( s ), text: ( 'schwenk' === s.art ? 'Schwenkwand' : 'Auszug' ) + ' → ' + ( ziel ? ziel.titel : '?' ) };
			} ),
			auswahl: ui.auswahl,
			zeichnen: !! ( ui.modus && 'geraet' === ui.modus.art ),
			punktSetzen: !! ( ui.modus && 'schalter' === ui.modus.art ),
			beiNeu: ( r ) => {
				const neu = { id: M.neueId( 'm' ), platz_id: ui.modus.platzId, ...r };
				bild.markierungen.push( neu );
				ui.modus = null;
				ui.auswahl = neu.id;
				gemerkt();
			},
			beiPunktNeu: ( p ) => {
				const neu = { id: M.neueId( 's' ), art: ui.modus.schalterArt, ziel_bild_id: ui.modus.zielBildId, ...p };
				bild.schalter.push( neu );
				ui.modus = null;
				ui.auswahl = neu.id;
				gemerkt();
			},
			beiAendern: ( id, r ) => { Object.assign( bild.markierungen.find( ( m ) => m.id === id ), r ); gemerkt( false ); },
			beiPunktAendern: ( id, p ) => { Object.assign( bild.schalter.find( ( s ) => s.id === id ), p ); gemerkt( false ); },
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
			auswahlInfo = el(
				'div',
				{ class: 'fwb-auswahl' },
				el( 'label', { for: 'fwb-marke-geraet', text: 'Markierung gehört zu: ' } ),
				el(
					'select',
					{ id: 'fwb-marke-geraet', onchange: ( e ) => { ausgewaehlteMarke.platz_id = e.target.value; gemerkt(); } },
					fach.plaetze.map( ( p ) => el( 'option', { value: p.id, text: geraetName( p.geraet ), selected: p.id === ausgewaehlteMarke.platz_id } ) )
				),
				' ',
				knopf( 'Markierung entfernen', () => { bild.markierungen = bild.markierungen.filter( ( m ) => m.id !== ausgewaehlteMarke.id ); ui.auswahl = null; gemerkt(); }, 'button-link fwb-gefahr' )
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
		} else if ( ui.modus && 'schalter' === ui.modus.art ) {
			auswahlInfo = el(
				'div',
				{ class: 'fwb-auswahl' },
				el( 'label', { for: 'fwb-neu-ziel', text: 'Der Schalter wechselt zu Bild: ' } ),
				el(
					'select',
					{ id: 'fwb-neu-ziel', onchange: ( e ) => { ui.modus.zielBildId = e.target.value; } },
					andereBilder.map( ( b ) => el( 'option', { value: b.id, text: ( fach.bilder.indexOf( b ) + 1 ) + '. ' + b.titel, selected: b.id === ui.modus.zielBildId } ) )
				)
			);
		}

		return el(
			'div',
			{ class: 'fwb-bilder' },
			el( 'h3', { text: 'Bilder von ' + fach.kuerzel } ),
			el( 'p', { class: 'description', text: 'Ein Bild pro Zustand, z. B. „Schwenkwand geschlossen“ und „Schwenkwand geöffnet“. Ein neues Bild übernimmt die Markierungen des aktuellen Bildes als Vorlage.' } ),
			bildReiter,
			el(
				'div',
				{ class: 'fwb-zeile' },
				textFeld( 'Titel des Bildes', bild.titel, ( w ) => { bild.titel = w; }, { max: 60, neuZeichnen: true } ),
				el(
					'div',
					{ class: 'fwb-zeile-rechts' },
					ordnenKnoepfe( fach.bilder, bildIndex, () => gemerkt() ),
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
			fotoFeld( bild.foto_id, ( id ) => { bild.foto_id = id; bild.skizze = ''; gemerkt(); } ),
			werkzeug,
			buehne,
			auswahlInfo
		);
	}

	/* ------------------------------ Start ------------------------------ */

	// Frühere Stände aus der Seitenleiste laden.
	document.addEventListener( 'click', ( e ) => {
		const knopfVersion = e.target.closest( '.fwb-version-laden' );
		if ( ! knopfVersion ) {
			return;
		}
		const json = daten.versionen[ Number( knopfVersion.dataset.version ) ];
		if ( ! json || ! window.confirm( 'Diesen früheren Stand in den Editor laden? Er wird erst mit „Aktualisieren“ gespeichert.' ) ) {
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

	zeichnen();
}
