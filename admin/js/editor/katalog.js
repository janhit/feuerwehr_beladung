/**
 * Zugriff auf den Gerätekatalog über die eigene REST-Schnittstelle
 * und das Suchfeld "Gerät hinzufügen".
 */

let rest = { wurzel: '', nonce: '' };

export function einrichten( konfiguration ) {
	rest = konfiguration;
}

export async function anfrage( pfad, optionen = {} ) {
	const antwort = await fetch( rest.wurzel + pfad, {
		credentials: 'same-origin',
		...optionen,
		headers: { 'X-WP-Nonce': rest.nonce, 'Content-Type': 'application/json', ...( optionen.headers || {} ) },
	} );
	const daten = await antwort.json().catch( () => ( {} ) );
	if ( ! antwort.ok ) {
		throw new Error( daten.message || 'Der Server hat die Anfrage abgelehnt.' );
	}
	return daten;
}

export function suchen( text ) {
	return anfrage( 'geraete?suche=' + encodeURIComponent( text ) );
}

export function anlegen( name ) {
	return anfrage( 'geraete', { method: 'POST', body: JSON.stringify( { name } ) } );
}

/**
 * Suchfeld mit Trefferliste. Ruft beiWahl({uid, name}) auf.
 * ausschliessen: Set von uids, die schon im Fach liegen.
 */
export function geraeteSuche( { beiWahl, ausschliessen = new Set() } ) {
	const kasten = document.createElement( 'div' );
	kasten.className = 'fwb-suche';

	const eingabe = document.createElement( 'input' );
	eingabe.type = 'search';
	eingabe.className = 'regular-text';
	eingabe.placeholder = 'Gerät suchen oder neu anlegen …';
	eingabe.setAttribute( 'aria-label', 'Gerät suchen oder neu anlegen' );

	const liste = document.createElement( 'ul' );
	liste.className = 'fwb-suche-treffer';
	liste.hidden = true;

	kasten.append( eingabe, liste );

	let zeitgeber = null;
	let laufend = 0;

	const zeigen = ( treffer, text ) => {
		liste.replaceChildren();
		const genau = treffer.some( ( t ) => t.name.toLowerCase() === text.toLowerCase() );
		for ( const t of treffer ) {
			const li = document.createElement( 'li' );
			const knopf = document.createElement( 'button' );
			knopf.type = 'button';
			knopf.className = 'button-link';
			knopf.textContent = t.name;
			if ( ausschliessen.has( t.uid ) ) {
				knopf.disabled = true;
				knopf.textContent += ' (schon in diesem Fach)';
			}
			knopf.addEventListener( 'click', () => {
				beiWahl( t );
				eingabe.value = '';
				liste.hidden = true;
			} );
			li.append( knopf );
			liste.append( li );
		}
		if ( text && ! genau ) {
			const li = document.createElement( 'li' );
			const knopf = document.createElement( 'button' );
			knopf.type = 'button';
			knopf.className = 'button button-small';
			knopf.textContent = '„' + text + '“ neu im Katalog anlegen';
			knopf.addEventListener( 'click', async () => {
				knopf.disabled = true;
				try {
					const antwort = await anlegen( text );
					beiWahl( antwort.geraet );
					eingabe.value = '';
					liste.hidden = true;
				} catch ( fehler ) {
					knopf.disabled = false;
					window.alert( fehler.message );
				}
			} );
			li.append( knopf );
			liste.append( li );
		}
		liste.hidden = ! liste.children.length;
	};

	const suchenUndZeigen = async () => {
		const text = eingabe.value.trim();
		const nummer = ++laufend;
		try {
			const treffer = await suchen( text );
			if ( nummer === laufend ) {
				zeigen( treffer, text );
			}
		} catch ( fehler ) {
			liste.replaceChildren( Object.assign( document.createElement( 'li' ), { textContent: fehler.message } ) );
			liste.hidden = false;
		}
	};

	eingabe.addEventListener( 'input', () => {
		clearTimeout( zeitgeber );
		zeitgeber = setTimeout( suchenUndZeigen, 250 );
	} );
	eingabe.addEventListener( 'focus', suchenUndZeigen );
	eingabe.addEventListener( 'keydown', ( e ) => {
		// Enter würde sonst das ganze Fahrzeug-Formular absenden.
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			const erster = liste.querySelector( 'button:not([disabled])' );
			if ( erster ) {
				erster.click();
			}
		}
		if ( 'Escape' === e.key ) {
			liste.hidden = true;
		}
	} );
	document.addEventListener( 'click', ( e ) => {
		if ( ! kasten.contains( e.target ) ) {
			liste.hidden = true;
		}
	} );

	return kasten;
}
