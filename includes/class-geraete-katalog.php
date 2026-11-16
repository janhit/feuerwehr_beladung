<?php
/**
 * Gerätekatalog für die ganze Seite.
 *
 * Jedes Gerät steht hier genau einmal (Name, Beschreibung, Einsatzhinweise,
 * Suchbegriffe) und hat eine feste Kennung (uid). Fahrzeuge verweisen nur
 * auf diese uid. Ein Gerät, das noch in einem Fahrzeug liegt, lässt sich
 * nicht löschen.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Geraete_Katalog {

	public const TYP           = 'fwb_geraet';
	public const META_UID      = '_fwb_uid';
	public const META_BESCHR   = '_fwb_beschreibung';
	public const META_HINWEISE = '_fwb_hinweise';
	public const META_SUCHE    = '_fwb_suchbegriffe';

	/** @var null|array<string, array> */
	private static ?array $zwischenspeicher = null;

	public static function registrieren(): void {
		register_post_type(
			self::TYP,
			array(
				'labels'              => array(
					'name'               => 'Gerätekatalog',
					'singular_name'      => 'Gerät',
					'menu_name'          => 'Gerätekatalog',
					'all_items'          => 'Gerätekatalog',
					'add_new'            => 'Neues Gerät',
					'add_new_item'       => 'Neues Gerät anlegen',
					'edit_item'          => 'Gerät bearbeiten',
					'search_items'       => 'Geräte durchsuchen',
					'not_found'          => 'Noch keine Geräte im Katalog.',
					'not_found_in_trash' => 'Keine Geräte im Papierkorb.',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Fahrzeug_Typ::TYP,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capabilities'        => Rechte::beitragstyp_rechte(),
				'map_meta_cap'        => false,
			)
		);
	}

	public static function haken(): void {
		add_action( 'add_meta_boxes_' . self::TYP, array( self::class, 'metaboxen' ) );
		add_action( 'save_post_' . self::TYP, array( self::class, 'speichern' ), 10, 2 );
		add_filter( 'pre_trash_post', array( self::class, 'loeschen_verhindern' ), 10, 2 );
		add_filter( 'pre_delete_post', array( self::class, 'loeschen_verhindern' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'hinweis_ausgeben' ) );
		// Vor WordPress abfangen, damit statt einer Fehlerseite ein Hinweis erscheint.
		add_action( 'admin_action_trash', array( self::class, 'einzeln_abfangen' ) );
		add_action( 'admin_action_delete', array( self::class, 'einzeln_abfangen' ) );
		add_action( 'load-edit.php', array( self::class, 'mehrfach_abfangen' ) );
		add_filter( 'manage_' . self::TYP . '_posts_columns', array( self::class, 'spalten' ) );
		add_action( 'manage_' . self::TYP . '_posts_custom_column', array( self::class, 'spalte_ausgeben' ), 10, 2 );
		add_filter( 'enter_title_here', array( self::class, 'titel_platzhalter' ), 10, 2 );
	}

	/* ------------------------------ Abfragen ------------------------------ */

	/** @return array<string, array{id:int, uid:string, name:string, beschreibung:string, hinweise:string, suchbegriffe:string}> */
	public static function alle(): array {
		if ( null !== self::$zwischenspeicher ) {
			return self::$zwischenspeicher;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::TYP,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$liste = array();
		foreach ( $posts as $post ) {
			$uid = (string) get_post_meta( $post->ID, self::META_UID, true );
			if ( '' === $uid ) {
				continue;
			}
			$liste[ $uid ] = self::als_array( $post, $uid );
		}
		self::$zwischenspeicher = $liste;
		return $liste;
	}

	public static function existiert( string $uid ): bool {
		return isset( self::alle()[ $uid ] );
	}

	public static function zwischenspeicher_leeren(): void {
		self::$zwischenspeicher = null;
	}

	/** Einfache Suche über Name und Suchbegriffe. */
	public static function suchen( string $text, int $max = 20 ): array {
		$text  = mb_strtolower( trim( $text ) );
		$treffer = array();
		foreach ( self::alle() as $geraet ) {
			$heuhaufen = mb_strtolower( $geraet['name'] . ' ' . $geraet['suchbegriffe'] );
			if ( '' === $text || str_contains( $heuhaufen, $text ) ) {
				$treffer[] = $geraet;
			}
			if ( count( $treffer ) >= $max ) {
				break;
			}
		}
		return $treffer;
	}

	/**
	 * Legt ein Gerät an. Gibt es den Namen schon, wird das vorhandene Gerät geliefert.
	 *
	 * @return string uid
	 */
	public static function anlegen( string $name, string $beschreibung = '', string $hinweise = '', string $suchbegriffe = '', string $uid = '' ): string {
		$name = mb_substr( sanitize_text_field( $name ), 0, 120 );
		if ( '' === $name ) {
			return '';
		}
		if ( '' !== $uid && self::existiert( $uid ) ) {
			return $uid;
		}
		foreach ( self::alle() as $vorhanden ) {
			if ( 0 === strcasecmp( $vorhanden['name'], $name ) ) {
				return $vorhanden['uid'];
			}
		}
		if ( '' === $uid || ! preg_match( Schema::GERAET_MUSTER, $uid ) ) {
			$uid = Schema::neue_id( 'g' );
		}
		$post_id = Protokoll::ohne_statuseintraege(
			static fn() => wp_insert_post(
				array(
					'post_type'   => self::TYP,
					'post_status' => 'publish',
					'post_title'  => $name,
				),
				true
			)
		);
		if ( is_wp_error( $post_id ) ) {
			return '';
		}
		update_post_meta( $post_id, self::META_UID, $uid );
		update_post_meta( $post_id, self::META_BESCHR, sanitize_textarea_field( $beschreibung ) );
		update_post_meta( $post_id, self::META_HINWEISE, sanitize_textarea_field( $hinweise ) );
		update_post_meta( $post_id, self::META_SUCHE, sanitize_text_field( $suchbegriffe ) );
		self::zwischenspeicher_leeren();
		return $uid;
	}

	/**
	 * Wo liegt ein Gerät überall?
	 *
	 * @return array<int, array{fahrzeug_id:int, fahrzeug:string, status:string, fach:string}>
	 */
	public static function verwendungen( string $uid ): array {
		$orte = array();
		foreach ( Beladung::alle_fahrzeug_ids() as $fahrzeug_id ) {
			$dok = Beladung::lesen( $fahrzeug_id );
			foreach ( $dok['faecher'] ?? array() as $fach ) {
				foreach ( $fach['plaetze'] ?? array() as $platz ) {
					if ( $platz['geraet'] === $uid ) {
						$orte[] = array(
							'fahrzeug_id' => $fahrzeug_id,
							'fahrzeug'    => get_the_title( $fahrzeug_id ),
							'status'      => get_post_status( $fahrzeug_id ),
							'fach'        => $fach['kuerzel'],
						);
					}
				}
			}
		}
		return $orte;
	}

	/* --------------------------- Bearbeiten --------------------------- */

	public static function titel_platzhalter( string $text, \WP_Post $post ): string {
		return self::TYP === $post->post_type ? 'Name des Geräts, z. B. Hydrantenschlüssel' : $text;
	}

	public static function metaboxen(): void {
		add_meta_box( 'fwb_geraet_daten', 'Angaben zum Gerät', array( self::class, 'metabox_daten' ), self::TYP, 'normal', 'high' );
		add_meta_box( 'fwb_geraet_orte', 'Liegt in', array( self::class, 'metabox_orte' ), self::TYP, 'side' );
	}

	public static function metabox_daten( \WP_Post $post ): void {
		wp_nonce_field( 'fwb_geraet_speichern', 'fwb_geraet_nonce' );
		$felder = array(
			self::META_BESCHR   => array( 'Beschreibung', 'Was ist das, wofür braucht man es?', true ),
			self::META_HINWEISE => array( 'Hinweise für den Einsatz', 'Worauf achten? Was gehört dazu?', true ),
			self::META_SUCHE    => array( 'Suchbegriffe', 'Andere Namen, durch Komma getrennt', false ),
		);
		echo '<div class="fwb-katalog-felder">';
		foreach ( $felder as $schluessel => [ $label, $platzhalter, $mehrzeilig ] ) {
			$wert = (string) get_post_meta( $post->ID, $schluessel, true );
			$id   = 'fwb' . $schluessel;
			echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
			if ( $mehrzeilig ) {
				echo '<textarea class="widefat" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" placeholder="' . esc_attr( $platzhalter ) . '">' . esc_textarea( $wert ) . '</textarea>';
			} else {
				echo '<input type="text" class="widefat" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $wert ) . '" placeholder="' . esc_attr( $platzhalter ) . '">';
			}
			echo '</p>';
		}
		echo '<p class="description">Menge und genaue Lage (z. B. „Schwenkwand, Rückseite“) trägst du je Fahrzeug im Fach ein, nicht hier.</p>';
		echo '</div>';
	}

	public static function metabox_orte( \WP_Post $post ): void {
		$uid = (string) get_post_meta( $post->ID, self::META_UID, true );
		if ( '' === $uid ) {
			echo '<p>Noch in keinem Fahrzeug.</p>';
			return;
		}
		$orte = self::verwendungen( $uid );
		if ( ! $orte ) {
			echo '<p>Noch in keinem Fahrzeug.</p>';
		} else {
			echo '<ul>';
			foreach ( $orte as $ort ) {
				$link = get_edit_post_link( $ort['fahrzeug_id'] );
				echo '<li><a href="' . esc_url( (string) $link ) . '">' . esc_html( $ort['fahrzeug'] ) . '</a>, ' . esc_html( $ort['fach'] );
				if ( 'publish' !== $ort['status'] ) {
					echo ' <em>(' . esc_html( self::status_text( (string) $ort['status'] ) ) . ')</em>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '<p class="description">Kennung: <code>' . esc_html( $uid ) . '</code></p>';
	}

	public static function speichern( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		// Jedes Gerät bekommt sofort eine feste Kennung.
		if ( '' === (string) get_post_meta( $post_id, self::META_UID, true ) && 'auto-draft' !== $post->post_status ) {
			update_post_meta( $post_id, self::META_UID, Schema::neue_id( 'g' ) );
		}
		self::zwischenspeicher_leeren();

		$nonce = isset( $_POST['fwb_geraet_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fwb_geraet_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'fwb_geraet_speichern' ) || ! current_user_can( Rechte::BEARBEITEN ) ) {
			return;
		}
		$werte = array(
			self::META_BESCHR   => isset( $_POST[ 'fwb' . self::META_BESCHR ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'fwb' . self::META_BESCHR ] ) ) : '',
			self::META_HINWEISE => isset( $_POST[ 'fwb' . self::META_HINWEISE ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'fwb' . self::META_HINWEISE ] ) ) : '',
			self::META_SUCHE    => isset( $_POST[ 'fwb' . self::META_SUCHE ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'fwb' . self::META_SUCHE ] ) ) : '',
		);
		$geaendert = false;
		foreach ( $werte as $schluessel => $wert ) {
			if ( (string) get_post_meta( $post_id, $schluessel, true ) !== $wert ) {
				update_post_meta( $post_id, $schluessel, $wert );
				$geaendert = true;
			}
		}
		if ( $geaendert ) {
			Protokoll::eintragen( 'geraet', $post_id, 'geändert', 'Gerät „' . get_the_title( $post_id ) . '“ bearbeitet' );
		}
	}

	/**
	 * Verhindert das Löschen eines Geräts, das noch in einem Fahrzeug liegt.
	 *
	 * @param mixed    $pruefen Bisheriges Ergebnis des Filters.
	 * @param \WP_Post $post    Beitrag.
	 * @return mixed
	 */
	public static function loeschen_verhindern( $pruefen, $post ) {
		if ( null !== $pruefen || ! $post instanceof \WP_Post || self::TYP !== $post->post_type ) {
			return $pruefen;
		}
		$uid = (string) get_post_meta( $post->ID, self::META_UID, true );
		if ( '' === $uid ) {
			return $pruefen;
		}
		$orte = self::verwendungen( $uid );
		if ( ! $orte ) {
			return $pruefen;
		}
		$wo = array_map( static fn( $o ) => $o['fahrzeug'] . ' ' . $o['fach'], $orte );
		set_transient(
			'fwb_hinweis_' . get_current_user_id(),
			'„' . $post->post_title . '“ wurde nicht gelöscht, weil es noch hier liegt: ' . implode( ', ', $wo ) . '. Entferne es zuerst aus diesen Fächern.',
			60
		);
		return false;
	}

	/** Klick auf „Papierkorb“ bzw. „Endgültig löschen“ bei einem einzelnen Gerät. */
	public static function einzeln_abfangen(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Lesen; WordPress prüft die Nonce danach.
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || self::TYP !== $post->post_type ) {
			return;
		}
		if ( false === self::loeschen_verhindern( null, $post ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::TYP ) );
			exit;
		}
	}

	/** Mehrfachaktion „In den Papierkorb“ in der Liste: benutzte Geräte herausnehmen. */
	public static function mehrfach_abfangen(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WordPress prüft die Nonce der Mehrfachaktion selbst.
		if ( ( $_REQUEST['post_type'] ?? '' ) !== self::TYP || empty( $_REQUEST['post'] ) || ! is_array( $_REQUEST['post'] ) ) {
			return;
		}
		$aktion = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) );
		if ( '-1' === $aktion || '' === $aktion ) {
			$aktion = sanitize_key( (string) ( $_REQUEST['action2'] ?? '' ) );
		}
		if ( ! in_array( $aktion, array( 'trash', 'delete' ), true ) ) {
			return;
		}
		$erlaubt   = array();
		$gesperrt  = array();
		foreach ( array_map( 'absint', (array) $_REQUEST['post'] ) as $id ) {
			$post = get_post( $id );
			$uid  = $post ? (string) get_post_meta( $id, self::META_UID, true ) : '';
			if ( $post && '' !== $uid && self::verwendungen( $uid ) ) {
				$gesperrt[] = $post->post_title;
			} else {
				$erlaubt[] = $id;
			}
		}
		// phpcs:enable
		if ( $gesperrt ) {
			$_REQUEST['post'] = $erlaubt;
			$_GET['post']     = $erlaubt;
			set_transient(
				'fwb_hinweis_' . get_current_user_id(),
				'Nicht gelöscht, weil sie noch in Fahrzeugen liegen: ' . implode( ', ', $gesperrt ) . '. Entferne sie zuerst aus den Fächern.',
				60
			);
		}
	}

	public static function hinweis_ausgeben(): void {
		$text = get_transient( 'fwb_hinweis_' . get_current_user_id() );
		if ( $text ) {
			delete_transient( 'fwb_hinweis_' . get_current_user_id() );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( (string) $text ) . '</p></div>';
		}
	}

	public static function spalten( array $spalten ): array {
		unset( $spalten['date'] );
		$spalten['fwb_beschreibung'] = 'Beschreibung';
		$spalten['fwb_orte']         = 'Liegt in';
		return $spalten;
	}

	public static function spalte_ausgeben( string $spalte, int $post_id ): void {
		if ( 'fwb_beschreibung' === $spalte ) {
			echo esc_html( wp_trim_words( (string) get_post_meta( $post_id, self::META_BESCHR, true ), 15 ) );
		}
		if ( 'fwb_orte' === $spalte ) {
			$uid  = (string) get_post_meta( $post_id, self::META_UID, true );
			$orte = '' === $uid ? array() : self::verwendungen( $uid );
			echo esc_html( $orte ? implode( ', ', array_map( static fn( $o ) => $o['fahrzeug'] . ' ' . $o['fach'], $orte ) ) : '–' );
		}
	}

	/* ------------------------------ Hilfen ------------------------------ */

	private static function als_array( \WP_Post $post, string $uid ): array {
		return array(
			'id'           => $post->ID,
			'uid'          => $uid,
			'name'         => $post->post_title,
			'beschreibung' => (string) get_post_meta( $post->ID, self::META_BESCHR, true ),
			'hinweise'     => (string) get_post_meta( $post->ID, self::META_HINWEISE, true ),
			'suchbegriffe' => (string) get_post_meta( $post->ID, self::META_SUCHE, true ),
		);
	}

	private static function status_text( string $status ): string {
		return array(
			'draft'   => 'Entwurf',
			'pending' => 'ausstehend',
			'private' => 'privat',
			'trash'   => 'Papierkorb',
			'future'  => 'geplant',
		)[ $status ] ?? $status;
	}
}
