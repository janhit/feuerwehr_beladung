/**
 * Lernseite "Beladung lernen".
 *
 * Ebene 1: Fahrzeugübersicht (in der Seite)
 * Ebene 2: Fahrzeug im Vollbild mit antippbaren Fächern
 * Ebene 3: Fach mit Rollladen, Markierungen und Geräteliste (oder Info/Suche)
 * Ebene 4: Gerätekarte über dem Foto
 *
 * Jede Ebene ist ein Eintrag in der Browser-Historie: Zurück-Taste, Wischgeste
 * und der Pfeil oben links führen genau eine Ebene nach oben.
 */

const wurzel = document.querySelector( '.fwb-lernen' );
if ( wurzel ) {
	const konfig = JSON.parse( wurzel.dataset.konfig || '{}' );
	const L = await import( new URL( './logik.js?ver=' + encodeURIComponent( konfig.version || '' ), import.meta.url ).href );
	starten( wurzel, konfig, L );
}

function starten( wurzel, konfig, L ) {
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
			} else if ( 'style' === k && 'object' === typeof v ) {
				Object.assign( e.style, v );
			} else if ( k.startsWith( 'data-' ) || k.startsWith( 'aria-' ) || 'role' === k ) {
				e.setAttribute( k, true === v ? '' : v );
			} else if ( k in e ) {
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

	function knopf( inhalt, beiKlick, klasse = 'fwb-l-knopf', extra = {} ) {
		return el( 'button', { type: 'button', class: klasse, onclick: beiKlick, ...extra }, inhalt );
	}

	function speicherLesen( schluessel, standard ) {
		try {
			return window.localStorage.getItem( schluessel ) ?? standard;
		} catch {
			return standard;
		}
	}

	function speicherSchreiben( schluessel, wert ) {
		try {
			window.localStorage.setItem( schluessel, wert );
		} catch {
			// Privater Modus o. Ä. – dann eben nicht merken.
		}
	}

	const bewegungAus = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* ------------------------------ Daten ------------------------------ */

	async function holen( pfad ) {
		const antwort = await fetch( konfig.rest + pfad, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': konfig.nonce },
		} );
		if ( 401 === antwort.status || 403 === antwort.status ) {
			throw new Error( 'Deine Anmeldung ist abgelaufen. Bitte die Seite neu laden und ggf. neu anmelden.' );
		}
		const daten = await antwort.json().catch( () => null );
		if ( ! antwort.ok || ! daten ) {
			const fehler = new Error( ( daten && daten.message ) || 'Die Daten konnten nicht geladen werden.' );
			fehler.status = antwort.status;
			throw fehler;
		}
		return daten;
	}

	let liste = null;
	const fahrzeuge = new Map();

	function fahrzeugLaden( id ) {
		if ( ! fahrzeuge.has( id ) ) {
			const versprechen = holen( 'fahrzeuge/' + id );
			versprechen.catch( () => fahrzeuge.delete( id ) );
			fahrzeuge.set( id, versprechen );
		}
		return fahrzeuge.get( id );
	}

	/* ------------------------------ Historie ------------------------------ */

	/*
	 * Jede Ebene ist ein Eintrag in der Browser-Historie (state.tiefe = 0…3).
	 * Springt das Mitglied quer (z. B. „Liegt auch in G1“), bleibt darunter ein
	 * veralteter Eintrag (Fach G3) stehen. Den kann die Seite nicht ändern, ohne
	 * selbst zurückzuspringen – also merkt sie sich eine Umleitung
	 * (Ebene + alte Adresse → richtige Adresse) im Sitzungsspeicher des Tabs.
	 * Das funktioniert auch, wenn der Browser beim Zurückgehen neu lädt.
	 */

	let zustand = L.zustandAusHash( window.location.hash );
	let tiefe = 0;

	function adresse( z ) {
		return window.location.pathname + window.location.search + L.hashAusZustand( z );
	}

	const UMLEITUNG = 'fwb-l-umleitungen:' + window.location.pathname;

	function umleitungen() {
		try {
			const liste = JSON.parse( window.sessionStorage.getItem( UMLEITUNG ) || '[]' );
			return Array.isArray( liste ) ? liste : [];
		} catch {
			return [];
		}
	}

	function umleitungenSpeichern( liste ) {
		try {
			window.sessionStorage.setItem( UMLEITUNG, JSON.stringify( liste.slice( -20 ) ) );
		} catch {
			// Ohne Speicher: Zurück zeigt dann im Ausnahmefall das alte Fach.
		}
	}

	/** Gibt es für diesen Eintrag eine Umleitung? Dann den richtigen Zustand. */
	function umgeleitet( t, hash ) {
		const u = umleitungen().find( ( x ) => x.tiefe === t && x.alt === hash );
		return u ? L.zustandAusHash( u.neu ) : null;
	}

	function eintragen( z, ersetzen ) {
		const daten = { fwb: true, tiefe };
		if ( ersetzen ) {
			window.history.replaceState( daten, '', adresse( z ) );
		} else {
			// Ab dieser Ebene sind alte Einträge weg – ihre Umleitungen auch.
			umleitungenSpeichern( umleitungen().filter( ( x ) => x.tiefe < tiefe ) );
			window.history.pushState( daten, '', adresse( z ) );
		}
	}

	/** Eine Ebene tiefer (neuer Eintrag in der Historie). */
	function tiefer( z ) {
		tiefe++;
		zustand = L.bereinigen( z );
		eintragen( zustand, false );
		zeichnen();
	}

	/** Gleiche Ebene, anderer Inhalt (Ansicht oder Bild wechseln). */
	function seitlich( z ) {
		zustand = L.bereinigen( z );
		eintragen( zustand, true );
		zeichnen();
	}

	function hoch() {
		if ( tiefe > 0 ) {
			window.history.back();
		} else {
			// Direkt hier eingestiegen ohne Historie: trotzdem eine Ebene hoch.
			const p = L.pfad( zustand );
			seitlich( p[ Math.max( 0, p.length - 2 ) ] );
		}
	}

	/**
	 * Zu einem Ziel auf gleicher oder tieferer Ebene springen (Suche → Gerät,
	 * „Liegt auch in G3“): aktuellen Eintrag ersetzen, ggf. tiefer gehen und
	 * für veraltete Einträge darunter Umleitungen merken.
	 */
	function geheZu( ziel ) {
		ziel = L.bereinigen( ziel );
		const zielPfad = L.pfad( ziel );
		const jetzt = L.pfad( zustand ).length;
		const gemeinsam = L.gemeinsameEbenen( zustand, ziel );
		if ( gemeinsam < jetzt ) {
			umleitungenSpeichern( umleitungen().concat( L.sprungUmleitungen( zustand, ziel ) ) );
			zustand = zielPfad[ Math.min( jetzt - 1, zielPfad.length - 1 ) ];
			eintragen( zustand, true );
		}
		for ( let i = jetzt; i < zielPfad.length; i++ ) {
			tiefe++;
			zustand = zielPfad[ i ];
			eintragen( zustand, false );
		}
		zustand = ziel;
		eintragen( zustand, true );
		zeichnen();
	}

	/** Zustand des aktuellen Eintrags lesen – mit Umleitung, falls nötig. */
	function eintragLesen() {
		zustand = L.zustandAusHash( window.location.hash );
		const richtig = umgeleitet( tiefe, L.hashAusZustand( zustand ) );
		if ( richtig ) {
			zustand = richtig;
			// Adresse erst nach dem Zurück-Schritt anpassen; währenddessen
			// verwerfen manche Browser Änderungen an der Historie.
			const fest = zustand;
			setTimeout( () => {
				if ( fest === zustand ) {
					eintragen( zustand, true );
				}
			}, 0 );
		}
	}

	window.addEventListener( 'popstate', ( e ) => {
		tiefe = e.state && e.state.fwb ? e.state.tiefe : 0;
		eintragLesen();
		zeichnen();
	} );

	( function start() {
		const st = window.history.state;
		if ( st && st.fwb ) {
			// Neu geladen (oder der Browser lädt beim Zurückgehen neu): Historie steht schon.
			tiefe = st.tiefe;
			eintragLesen();
			return;
		}
		// Link direkt in ein Fach geöffnet: Historie Ebene für Ebene aufbauen,
		// damit „Zurück“ genau eine Ebene nach oben führt.
		umleitungenSpeichern( [] );
		const p = L.pfad( zustand );
		tiefe = 0;
		eintragen( p[ 0 ], true );
		for ( let i = 1; i < p.length; i++ ) {
			tiefe++;
			eintragen( i === p.length - 1 ? zustand : p[ i ], false );
		}
	}() );

	document.addEventListener( 'keydown', ( e ) => {
		if ( 'Escape' === e.key && L.ebene( zustand ) > 1 && ! e.defaultPrevented ) {
			e.preventDefault();
			hoch();
		}
	} );

	/* ------------------------------ Bilder ------------------------------ */

	function bildElement( bild, klasse = '' ) {
		if ( ! bild ) {
			return el( 'div', { class: 'fwb-l-ohne-foto ' + klasse, text: 'Noch kein Foto' } );
		}
		return el( 'img', {
			class: klasse,
			src: bild.klein,
			srcset: bild.skizze ? null : bild.klein + ' ' + bild.klein_b + 'w, ' + bild.gross + ' ' + bild.gross_b + 'w',
			sizes: bild.skizze ? null : '(min-width: 900px) 70vw, 100vw',
			alt: '',
			draggable: false,
			decoding: 'async',
		} );
	}

	/**
	 * Bühne: Foto mit Flächen darüber, so groß wie möglich. Sehr breite Fotos
	 * im Hochformat werden seitlich gewischt.
	 */
	function buehne( bild, ebenen, { klasse = '', blende = false, rollo = false } = {} ) {
		const verhaeltnis = bild ? bild.verhaeltnis : 4 / 3;
		const innen = el( 'div', { class: 'fwb-l-bild', style: { aspectRatio: String( verhaeltnis ) } }, bildElement( bild, blende && ! bewegungAus ? 'fwb-l-blende' : '' ), ebenen );
		let rolloElement = null;
		if ( rollo && ! bewegungAus ) {
			rolloElement = el( 'div', { class: 'fwb-l-rollo', 'aria-hidden': 'true' }, el( 'span', { class: 'fwb-l-rollo-griff' } ) );
			innen.append( rolloElement );
		}
		const rahmen = el( 'div', { class: 'fwb-l-rahmen ' + klasse }, innen );
		const anpassen = () => {
			const g = L.buehnenGroesse( rahmen.clientWidth, rahmen.clientHeight, verhaeltnis );
			innen.style.width = g.breite + 'px';
			innen.style.height = g.hoehe + 'px';
			rahmen.classList.toggle( 'ist-wischen', g.wischen );
		};
		requestAnimationFrame( () => {
			anpassen();
			if ( rolloElement ) {
				// Zwei Bilder später starten, damit der Browser den Ausgangszustand gezeichnet hat.
				requestAnimationFrame( () => requestAnimationFrame( () => rolloElement.classList.add( 'ist-offen' ) ) );
				rolloElement.addEventListener( 'transitionend', () => rolloElement.remove(), { once: true } );
			}
		} );
		if ( window.ResizeObserver ) {
			new ResizeObserver( anpassen ).observe( rahmen );
		}
		return rahmen;
	}

	function flaeche( r, { klasse, beschriftung, titel, beiKlick, dataPlatz } ) {
		return el(
			'button',
			{
				type: 'button',
				class: 'fwb-l-flaeche ' + klasse,
				style: { left: r.x + '%', top: r.y + '%', width: r.b + '%', height: r.h + '%' },
				'aria-label': titel,
				title: titel,
				'data-platz': dataPlatz || null,
				onclick: beiKlick,
			},
			beschriftung ? el( 'span', { class: 'fwb-l-flaeche-text', text: beschriftung } ) : null
		);
	}

	/* ------------------------------ Gerüst ------------------------------ */

	let vollbild = null;
	let letzteEbene = 1;
	let letztesFach = null;
	let letztesBild = null;

	function vollbildOeffnen() {
		if ( ! vollbild ) {
			vollbild = el( 'div', { class: 'fwb-l-vollbild', role: 'dialog', 'aria-modal': 'true' } );
			document.body.append( vollbild );
			document.documentElement.classList.add( 'fwb-l-offen' );
		}
		return vollbild;
	}

	function vollbildSchliessen() {
		if ( vollbild ) {
			vollbild.remove();
			vollbild = null;
			document.documentElement.classList.remove( 'fwb-l-offen' );
		}
	}

	function kopf( titel, untertitel, aktionen = [] ) {
		return el(
			'header',
			{ class: 'fwb-l-kopf' },
			knopf( el( 'span', { 'aria-hidden': 'true', text: '‹' } ), hoch, 'fwb-l-zurueck', { 'aria-label': 'Zurück', title: 'Zurück' } ),
			el( 'div', { class: 'fwb-l-titel' }, el( 'strong', { text: titel } ), untertitel ? el( 'span', { text: untertitel } ) : null ),
			el( 'div', { class: 'fwb-l-aktionen' }, aktionen )
		);
	}

	function meldungZeigen( ziel, text, nochmal ) {
		ziel.replaceChildren( el( 'div', { class: 'fwb-l-meldung' }, el( 'p', { text } ), nochmal ? knopf( 'Erneut versuchen', nochmal ) : null ) );
	}

	/* ------------------------------ Ebene 1: Übersicht ------------------------------ */

	async function uebersicht() {
		if ( ! liste ) {
			try {
				liste = await holen( 'fahrzeuge' );
			} catch ( fehler ) {
				meldungZeigen( wurzel, fehler.message, () => uebersicht() );
				return;
			}
		}
		if ( ! liste.length ) {
			wurzel.replaceChildren( el( 'p', { class: 'fwb-l-meldung', text: 'Noch keine Fahrzeuge veröffentlicht.' } ) );
			return;
		}
		wurzel.replaceChildren(
			el(
				'div',
				{ class: 'fwb-l-karten' },
				liste.map( ( f ) =>
					el(
						'button',
						{ type: 'button', class: 'fwb-l-karte', onclick: () => tiefer( { f: String( f.id ) } ) },
						el( 'span', { class: 'fwb-l-karte-bild' }, bildElement( f.foto ) ),
						el(
							'span',
							{ class: 'fwb-l-karte-text' },
							el( 'strong', { class: 'fwb-l-karte-titel', text: f.titel } ),
							f.entwurf ? el( 'span', { class: 'fwb-l-entwurf', text: 'Entwurf – nur für Redakteure sichtbar' } ) : null,
							f.eckdaten.length ? el( 'span', { class: 'fwb-l-eckdaten' }, f.eckdaten.map( ( e ) => el( 'span', {}, el( 'small', { text: e.label } ), ' ', e.wert ) ) ) : null,
							f.kurz ? el( 'span', { class: 'fwb-l-kurz', text: f.kurz } ) : null,
							el( 'span', { class: 'fwb-l-zahlen', text: f.faecher + ' Fächer · ' + f.geraete + ' Geräte' } )
						)
					)
				)
			)
		);
	}

	/* ------------------------------ Ebene 2: Fahrzeug ------------------------------ */

	function fahrzeugAnsicht( fz ) {
		const ansicht = fz.ansichten.find( ( a ) => a.id === zustand.a ) || fz.ansichten[ 0 ] || null;
		const fachKnoepfe = el(
			'div',
			{ class: 'fwb-l-faecher' },
			el( 'span', { class: 'fwb-l-faecher-titel', text: 'Fächer:' } ),
			fz.faecher.map( ( f ) => knopf( f.kuerzel, () => tiefer( { ...zustand, a: ansicht ? ansicht.id : undefined, fach: f.id } ), 'fwb-l-chip', { title: f.name || f.kuerzel } ) )
		);

		const reiter = fz.ansichten.length > 1
			? el(
				'div',
				{ class: 'fwb-l-reiter', role: 'tablist', 'aria-label': 'Ansicht wechseln' },
				fz.ansichten.map( ( a ) =>
					el( 'button', {
						type: 'button',
						role: 'tab',
						class: 'fwb-l-reiter-knopf' + ( a === ansicht ? ' ist-aktiv' : '' ),
						'aria-selected': a === ansicht ? 'true' : 'false',
						text: a.name,
						onclick: () => seitlich( { ...zustand, a: a.id } ),
					} )
				)
			)
			: null;

		let mitte;
		if ( ansicht ) {
			const fachVon = ( id ) => L.fachVon( fz, id );
			mitte = buehne(
				ansicht.bild,
				ansicht.bereiche.map( ( r ) => {
					const f = fachVon( r.fach_id );
					return f
						? flaeche( r, {
							klasse: 'ist-fach',
							beschriftung: f.kuerzel,
							titel: 'Fach ' + f.kuerzel + ( f.name ? ' – ' + f.name : '' ) + ' öffnen',
							beiKlick: () => tiefer( { ...zustand, a: ansicht.id, fach: f.id } ),
						} )
						: null;
				} ),
				{ klasse: 'fwb-l-rahmen-fahrzeug' }
			);
		} else {
			mitte = el( 'p', { class: 'fwb-l-meldung', text: 'Für dieses Fahrzeug gibt es noch keine Außenansicht. Wähle unten ein Fach.' } );
		}

		return [
			kopf( fz.titel, fz.entwurf ? 'Entwurf' : ( ansicht ? ansicht.name : '' ), [
				knopf( [ el( 'span', { 'aria-hidden': 'true', text: '🔍' } ), el( 'span', { class: 'fwb-l-lang', text: ' Suche' } ) ], () => tiefer( { ...zustand, p: 'suche' } ), 'fwb-l-aktion', { 'aria-label': 'Gerät suchen' } ),
				knopf( [ el( 'span', { 'aria-hidden': 'true', text: 'ⓘ' } ), el( 'span', { class: 'fwb-l-lang', text: ' Info' } ) ], () => tiefer( { ...zustand, p: 'info' } ), 'fwb-l-aktion', { 'aria-label': 'Infos zum Fahrzeug' } ),
			] ),
			reiter,
			el( 'div', { class: 'fwb-l-mitte' }, mitte ),
			el( 'div', { class: 'fwb-l-fuss' }, ansicht ? el( 'p', { class: 'fwb-l-tipp', text: 'Tippe auf ein Gerätefach.' } ) : null, fachKnoepfe ),
		];
	}

	/* ------------------------------ Ebene 3: Info und Suche ------------------------------ */

	function info( fz ) {
		const s = fz.steckbrief;
		return [
			kopf( 'Info', fz.titel ),
			el(
				'div',
				{ class: 'fwb-l-blatt' },
				s.foto ? el( 'div', { class: 'fwb-l-info-foto' }, bildElement( s.foto ) ) : null,
				s.kurz ? el( 'p', { class: 'fwb-l-info-kurz', text: s.kurz } ) : null,
				s.felder.length
					? el( 'dl', { class: 'fwb-l-felder' }, s.felder.map( ( f ) => [ el( 'dt', { text: f.label } ), el( 'dd', { text: f.wert } ) ] ) )
					: null,
				! s.kurz && ! s.felder.length ? el( 'p', { text: 'Für dieses Fahrzeug ist noch kein Steckbrief hinterlegt.' } ) : null,
				el( 'p', { class: 'fwb-l-zahlen', text: fz.faecher.length + ' Fächer · ' + fz.faecher.reduce( ( n, f ) => n + f.plaetze.length, 0 ) + ' Geräte' } )
			),
		];
	}

	function suche( fz ) {
		const ergebnis = el( 'div', { class: 'fwb-l-treffer', 'aria-live': 'polite' } );
		const eingabe = el( 'input', {
			type: 'search',
			class: 'fwb-l-suchfeld',
			placeholder: 'Gerät suchen, z. B. Leine',
			'aria-label': 'Gerät suchen',
			autocomplete: 'off',
			enterKeyHint: 'search',
		} );
		const aktualisieren = () => {
			const text = eingabe.value;
			const treffer = L.suchen( fz, text );
			if ( ! text.trim() ) {
				ergebnis.replaceChildren( el( 'p', { class: 'fwb-l-tipp', text: 'Tippe einen Gerätenamen ein. Gesucht wird auch nach anderen Bezeichnungen.' } ) );
				return;
			}
			if ( ! treffer.length ) {
				ergebnis.replaceChildren( el( 'p', { class: 'fwb-l-tipp', text: 'Nichts gefunden.' } ) );
				return;
			}
			ergebnis.replaceChildren(
				el(
					'ul',
					{},
					treffer.slice( 0, 40 ).map( ( t ) =>
						el(
							'li',
							{},
							el( 'strong', { text: t.name } ),
							el(
								'span',
								{ class: 'fwb-l-orte' },
								t.orte.map( ( o ) =>
									knopf(
										[ el( 'b', { text: o.kuerzel } ), o.lage ? ' · ' + o.lage : '' ],
										() => geheZu( { f: zustand.f, a: zustand.a, fach: o.fachId, g: o.platzId } ),
										'fwb-l-chip',
										{ 'aria-label': t.name + ' in Fach ' + o.kuerzel + ' zeigen' }
									)
								)
							)
						)
					)
				)
			);
		};
		eingabe.addEventListener( 'input', aktualisieren );
		aktualisieren();
		requestAnimationFrame( () => eingabe.focus() );
		return [ kopf( 'Suche', fz.titel ), el( 'div', { class: 'fwb-l-blatt' }, eingabe, ergebnis ) ];
	}

	/* ------------------------------ Ebene 3 und 4: Fach und Gerät ------------------------------ */

	function fachAnsicht( fz, fach ) {
		// Ohne gewähltes Bild: das erste, auf dem das gewählte Gerät zu sehen ist.
		const bildId = zustand.b || L.bildFuerPlatz( fach, null, zustand.g );
		const bild = fach.bilder.find( ( b ) => b.id === bildId ) || fach.bilder[ 0 ];
		const platz = L.platzVon( fach, zustand.g );
		const markierungenAn = 'aus' !== speicherLesen( 'fwb-l-markierungen', 'an' );
		const neuGeoeffnet = letztesFach !== fach.id;
		const blende = ! neuGeoeffnet && letztesBild && letztesBild !== bild.id;
		letztesFach = fach.id;
		letztesBild = bild.id;

		const geraetName = ( p ) => ( fz.geraete[ p.geraet ] || { name: '?' } ).name;
		const waehlen = ( p ) => {
			const ziel = { ...zustand, g: p.id, b: L.bildFuerPlatz( fach, bild.id, p.id ) };
			if ( zustand.g ) {
				seitlich( ziel );
			} else {
				tiefer( ziel );
			}
		};

		// Flächen der Geräte und Schalter
		const ebenen = [];
		for ( const m of bild.markierungen ) {
			const p = L.platzVon( fach, m.platz_id );
			if ( ! p ) {
				continue;
			}
			const gewaehlt = platz && platz.id === p.id;
			const sichtbar = markierungenAn || gewaehlt;
			ebenen.push(
				flaeche( m, {
					klasse: 'ist-geraet' + ( gewaehlt ? ' ist-gewaehlt' : '' ) + ( platz && ! gewaehlt ? ' ist-gedimmt' : '' ) + ( sichtbar ? '' : ' ist-unsichtbar' ),
					beschriftung: gewaehlt || markierungenAn ? geraetName( p ) + ( m.notiz ? ' · ' + m.notiz : '' ) : '',
					titel: geraetName( p ) + ( m.notiz ? ' (' + m.notiz + ')' : '' ),
					dataPlatz: p.id,
					beiKlick: () => waehlen( p ),
				} )
			);
		}
		for ( const s of bild.schalter ) {
			const richtung = L.schalterRichtung( fach, bild.id, s.ziel_bild_id );
			ebenen.push(
				el( 'button', {
					type: 'button',
					class: 'fwb-l-schalter ist-' + s.art,
					style: { left: s.x + '%', top: s.y + '%' },
					text: L.schalterSymbol( s.art, richtung ),
					'aria-label': L.schalterText( s.art, richtung ),
					title: L.schalterText( s.art, richtung ),
					onclick: () => seitlich( { ...zustand, b: s.ziel_bild_id } ),
				} )
			);
		}

		const bildReiter = fach.bilder.length > 1
			? el(
				'div',
				{ class: 'fwb-l-bilder', role: 'group', 'aria-label': 'Zustand des Fachs' },
				fach.bilder.map( ( b ) =>
					el( 'button', {
						type: 'button',
						class: 'fwb-l-chip' + ( b.id === bild.id ? ' ist-aktiv' : '' ),
						'aria-pressed': b.id === bild.id ? 'true' : 'false',
						text: b.titel,
						onclick: () => seitlich( { ...zustand, b: b.id } ),
					} )
				)
			)
			: null;

		const geraeteListe = el(
			'ul',
			{ class: 'fwb-l-geraete' },
			fach.plaetze.map( ( p ) => {
				const hier = bild.markierungen.some( ( m ) => m.platz_id === p.id );
				const anderes = ! hier && fach.bilder.find( ( b ) => b.markierungen.some( ( m ) => m.platz_id === p.id ) );
				return el(
					'li',
					{},
					el(
						'button',
						{
							type: 'button',
							class: 'fwb-l-geraet' + ( platz && platz.id === p.id ? ' ist-gewaehlt' : '' ),
							'data-platz': p.id,
							onclick: () => waehlen( p ),
						},
						el( 'span', { class: 'fwb-l-geraet-name' }, p.menge && '1' !== p.menge ? el( 'b', { class: 'fwb-l-menge', text: p.menge + '×' } ) : null, geraetName( p ) ),
						p.lage ? el( 'span', { class: 'fwb-l-geraet-lage', text: p.lage } ) : null,
						anderes ? el( 'span', { class: 'fwb-l-geraet-lage', text: '→ ' + anderes.titel } ) : null
					)
				);
			} )
		);

		const karte = platz ? geraeteKarte( fz, fach, platz, bild ) : null;

		const umschalter = knopf(
			[ el( 'span', { 'aria-hidden': 'true', text: markierungenAn ? '▣' : '▢' } ), el( 'span', { class: 'fwb-l-lang', text: ' Markierungen' } ) ],
			() => {
				speicherSchreiben( 'fwb-l-markierungen', markierungenAn ? 'aus' : 'an' );
				zeichnen();
			},
			'fwb-l-aktion',
			{ 'aria-pressed': markierungenAn ? 'true' : 'false', 'aria-label': 'Markierungen ' + ( markierungenAn ? 'ausblenden' : 'einblenden' ), title: 'Markierungen ein/aus' }
		);

		return [
			kopf( fach.kuerzel + ( fach.name ? ' · ' + fach.name : '' ), fz.titel, [ umschalter ] ),
			el(
				'div',
				{ class: 'fwb-l-fach' + ( karte ? ' hat-karte' : '' ) },
				el(
					'div',
					{ class: 'fwb-l-fach-bild' },
					buehne( bild.bild, ebenen, { klasse: 'fwb-l-rahmen-fach', blende, rollo: neuGeoeffnet } ),
					bildReiter
				),
				el(
					'div',
					{ class: 'fwb-l-fach-liste' },
					el( 'h2', { class: 'fwb-l-ueberschrift', text: fach.plaetze.length + ( 1 === fach.plaetze.length ? ' Gerät' : ' Geräte' ) } ),
					fach.plaetze.length ? geraeteListe : el( 'p', { class: 'fwb-l-tipp', text: 'In diesem Fach sind noch keine Geräte eingetragen.' } )
				),
				karte
			),
		];
	}

	function absaetze( text ) {
		return String( text || '' ).split( /\n{2,}|\r\n\r\n/ ).filter( ( t ) => t.trim() ).map( ( t ) => el( 'p', { text: t.trim() } ) );
	}

	function geraeteKarte( fz, fach, platz, bild ) {
		const g = fz.geraete[ platz.geraet ] || { name: '?', beschreibung: '', hinweise: '' };
		const stellen = fach.bilder.flatMap( ( b ) => b.markierungen.filter( ( m ) => m.platz_id === platz.id && m.notiz ).map( ( m ) => m.notiz ) );
		const auchIn = L.orteVonGeraet( fz, platz.geraet ).filter( ( o ) => o.fachId !== fach.id );
		const nichtHier = ! bild.markierungen.some( ( m ) => m.platz_id === platz.id );

		return el(
			'aside',
			{ class: 'fwb-l-karte-geraet', 'aria-label': 'Gerät ' + g.name },
			el(
				'div',
				{ class: 'fwb-l-karte-kopf' },
				el( 'h2', { text: g.name } ),
				knopf( '×', hoch, 'fwb-l-schliessen', { 'aria-label': 'Gerätekarte schließen' } )
			),
			el(
				'dl',
				{ class: 'fwb-l-felder' },
				platz.menge ? [ el( 'dt', { text: 'Menge' } ), el( 'dd', { text: platz.menge } ) ] : null,
				platz.lage ? [ el( 'dt', { text: 'Lage' } ), el( 'dd', { text: platz.lage } ) ] : null,
				stellen.length ? [ el( 'dt', { text: 'Stellen' } ), el( 'dd', { text: [ ...new Set( stellen ) ].join( ', ' ) } ) ] : null
			),
			nichtHier ? el( 'p', { class: 'fwb-l-tipp', text: 'Auf diesem Bild nicht zu sehen.' } ) : null,
			platz.notiz ? el( 'p', { class: 'fwb-l-notiz', text: platz.notiz } ) : null,
			g.beschreibung ? el( 'div', { class: 'fwb-l-beschreibung' }, absaetze( g.beschreibung ) ) : null,
			g.hinweise ? el( 'div', { class: 'fwb-l-hinweise' }, el( 'strong', { text: 'Einsatzhinweise' } ), absaetze( g.hinweise ) ) : null,
			auchIn.length
				? el(
					'div',
					{ class: 'fwb-l-auch' },
					el( 'span', { text: 'Liegt auch in: ' } ),
					auchIn.map( ( o ) => knopf( o.kuerzel, () => geheZu( { f: zustand.f, a: zustand.a, fach: o.fachId, g: o.platzId } ), 'fwb-l-chip', { title: o.lage || o.name || o.kuerzel } ) )
				)
				: null
		);
	}

	/* ------------------------------ Zeichnen ------------------------------ */

	let zeichenNummer = 0;

	async function zeichnen() {
		const nummer = ++zeichenNummer;
		const stufe = L.ebene( zustand );
		const ebeneGewechselt = stufe !== letzteEbene;
		letzteEbene = stufe;

		if ( 1 === stufe ) {
			vollbildSchliessen();
			letztesFach = null;
			await uebersicht();
			return;
		}

		const ziel = vollbildOeffnen();
		let fz;
		try {
			if ( ! fahrzeuge.has( zustand.f ) ) {
				ziel.replaceChildren( kopf( 'Wird geladen …', '' ), el( 'p', { class: 'fwb-l-meldung', text: 'Fahrzeug wird geladen …' } ) );
			}
			fz = await fahrzeugLaden( zustand.f );
		} catch ( fehler ) {
			if ( nummer === zeichenNummer ) {
				ziel.replaceChildren( kopf( 'Fehler', '' ) );
				const box = el( 'div' );
				ziel.append( box );
				meldungZeigen( box, fehler.message, 404 === fehler.status ? null : () => zeichnen() );
			}
			return;
		}
		if ( nummer !== zeichenNummer ) {
			return; // Inzwischen wurde schon woanders hin navigiert.
		}

		let inhalt;
		const fach = zustand.fach ? L.fachVon( fz, zustand.fach ) : null;
		if ( zustand.fach && ! fach ) {
			// Fach gibt es nicht mehr (z. B. alter Link): eine Ebene höher.
			seitlich( { f: zustand.f, a: zustand.a } );
			return;
		}
		if ( fach ) {
			if ( zustand.g && ! L.platzVon( fach, zustand.g ) ) {
				seitlich( { ...zustand, g: undefined } );
				return;
			}
			inhalt = fachAnsicht( fz, fach );
		} else {
			letztesFach = null;
			if ( 'info' === zustand.p ) {
				inhalt = info( fz );
			} else if ( 'suche' === zustand.p ) {
				inhalt = suche( fz );
			} else {
				inhalt = fahrzeugAnsicht( fz );
			}
		}
		ziel.className = 'fwb-l-vollbild ist-ebene-' + stufe;
		ziel.replaceChildren( ...inhalt.filter( Boolean ) );
		if ( ebeneGewechselt && 'suche' !== zustand.p ) {
			const zurueck = ziel.querySelector( '.fwb-l-zurueck' );
			if ( zurueck ) {
				zurueck.focus( { preventScroll: true } );
			}
		}
	}

	zeichnen();
}
