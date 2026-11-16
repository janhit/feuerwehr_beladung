<?php
/**
 * Backend-Editor für die Beladung eines Fahrzeugs.
 *
 * Der Editor ist eine kleine JavaScript-Anwendung in einer Metabox. Sie
 * schreibt das ganze Dokument als JSON in ein verstecktes Feld; beim
 * Speichern des Fahrzeugs wird es auf dem Server bereinigt (Schema).
 * Läuft das JavaScript nicht, bleibt das Feld unverändert und es geht
 * nichts verloren.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Editor {

	/** Modul => Module, die es importiert. */
	private const MODULE = array(
		'modell'    => array(),
		'markierer' => array( 'modell' ),
		'katalog'   => array(),
		'main'      => array( 'modell', 'markierer', 'katalog' ),
	);

	public static function haken(): void {
		add_action( 'add_meta_boxes_' . Fahrzeug_Typ::TYP, array( self::class, 'metaboxen' ) );
		add_action( 'save_post_' . Fahrzeug_Typ::TYP, array( self::class, 'speichern' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'skripte' ) );
		add_action( 'admin_notices', array( self::class, 'hinweise_ausgeben' ) );
		add_filter( 'enter_title_here', array( self::class, 'titel_platzhalter' ), 10, 2 );
	}

	public static function titel_platzhalter( string $text, \WP_Post $post ): string {
		return Fahrzeug_Typ::TYP === $post->post_type ? 'Name des Fahrzeugs, z. B. HLF 1' : $text;
	}

	public static function metaboxen(): void {
		add_meta_box( 'fwb_editor', 'Beladung', array( self::class, 'metabox' ), Fahrzeug_Typ::TYP, 'normal', 'high' );
		add_meta_box( 'fwb_versionen', 'Frühere Stände', array( self::class, 'metabox_versionen' ), Fahrzeug_Typ::TYP, 'side', 'low' );
	}

	public static function metabox( \WP_Post $post ): void {
		$dok = Beladung::lesen( $post->ID );
		wp_nonce_field( 'fwb_editor_speichern', 'fwb_editor_nonce' );
		echo '<textarea name="fwb_beladung" id="fwb-beladung-json" hidden>' . esc_textarea( (string) wp_json_encode( $dok, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';
		echo '<script type="application/json" id="fwb-editor-daten">' . self::daten_json( $post->ID, $dok ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON mit JSON_HEX_TAG.
		echo '<div id="fwb-editor" class="fwb-editor"><p class="fwb-laedt">Editor wird geladen … Falls dieser Text bleibt, ist JavaScript im Browser ausgeschaltet.</p></div>';
	}

	public static function metabox_versionen( \WP_Post $post ): void {
		$versionen = Beladung::versionen( $post->ID );
		if ( ! $versionen ) {
			echo '<p>Noch keine früheren Stände. Bei jedem Speichern wird der vorherige Stand hier aufgehoben (die letzten ' . (int) Beladung::MAX_VERSIONEN . ').</p>';
			return;
		}
		echo '<ul class="fwb-versionen">';
		foreach ( $versionen as $i => $v ) {
			$user = get_userdata( (int) $v['user_id'] );
			echo '<li>' . esc_html( wp_date( 'd.m.Y H:i', (int) $v['zeit'] ) ) . ( $user ? ' · ' . esc_html( $user->display_name ) : '' );
			echo ' <button type="button" class="button-link fwb-version-laden" data-version="' . (int) $i . '">laden</button></li>';
		}
		echo '</ul><p class="description">„Laden“ holt den Stand in den Editor. Erst mit „Speichern“ wird er gespeichert; „Rückgängig“ nimmt das Laden zurück.</p>';
	}

	private static function daten_json( int $post_id, array $dok ): string {
		$fotos = array();
		$ids   = array( (int) ( $dok['steckbrief']['foto_id'] ?? 0 ) );
		foreach ( $dok['ansichten'] ?? array() as $a ) {
			$ids[] = (int) $a['foto_id'];
		}
		foreach ( $dok['faecher'] ?? array() as $f ) {
			foreach ( $f['bilder'] ?? array() as $b ) {
				$ids[] = (int) $b['foto_id'];
			}
		}
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$bild = wp_get_attachment_image_src( $id, 'large' );
			if ( $bild ) {
				$fotos[ $id ] = array( 'url' => $bild[0], 'b' => $bild[1], 'h' => $bild[2] );
			}
		}

		$skizzen = array();
		foreach ( Schema::SKIZZEN as $s ) {
			$skizzen[ $s ] = FWB_URL . 'assets/skizzen/' . $s . '.svg';
		}

		$katalog = array();
		foreach ( Geraete_Katalog::alle() as $g ) {
			$katalog[ $g['uid'] ] = array(
				'uid'        => $g['uid'],
				'name'       => $g['name'],
				'bearbeiten' => (string) get_edit_post_link( $g['id'], 'raw' ),
			);
		}

		$versionen = array();
		foreach ( Beladung::versionen( $post_id ) as $v ) {
			$versionen[] = $v['json'];
		}

		return (string) wp_json_encode(
			array(
				'dokument'  => $dok,
				'fotos'     => $fotos,
				'skizzen'   => $skizzen,
				'katalog'   => $katalog,
				'versionen' => $versionen,
				'rest'      => array(
					'wurzel' => esc_url_raw( rest_url( Rest::NAMENSRAUM . '/' ) ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
				),
				'links'     => array(
					'katalog' => admin_url( 'edit.php?post_type=' . Geraete_Katalog::TYP ),
				),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
		);
	}

	public static function skripte( string $seite ): void {
		$bildschirm = get_current_screen();
		if ( ! $bildschirm || Fahrzeug_Typ::TYP !== $bildschirm->post_type || ! in_array( $seite, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'fwb-editor', FWB_URL . 'admin/css/editor.css', array(), Plugin::datei_version( 'admin/css/editor.css' ) );
		// Jedes Modul mit seinen Abhängigkeiten registrieren; WordPress baut daraus
		// die Import-Map (mit Versionsnummer, damit nach Updates nichts Altes im Cache bleibt).
		foreach ( self::MODULE as $modul => $abhaengig ) {
			if ( 'main' === $modul ) {
				continue;
			}
			wp_register_script_module(
				'@fwb/editor/' . $modul,
				FWB_URL . 'admin/js/editor/' . $modul . '.js',
				array_map( static fn( $m ) => '@fwb/editor/' . $m, $abhaengig ),
				Plugin::datei_version( 'admin/js/editor/' . $modul . '.js' )
			);
		}
		wp_enqueue_script_module(
			'@fwb/editor/main',
			FWB_URL . 'admin/js/editor/main.js',
			array_map( static fn( $m ) => '@fwb/editor/' . $m, self::MODULE['main'] ),
			Plugin::datei_version( 'admin/js/editor/main.js' )
		);
	}

	public static function speichern( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['fwb_editor_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fwb_editor_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'fwb_editor_speichern' ) || ! current_user_can( Rechte::BEARBEITEN ) ) {
			return;
		}
		if ( ! isset( $_POST['fwb_beladung'] ) ) {
			return;
		}
		// Rohes JSON; jedes Feld wird danach im Schema einzeln bereinigt.
		$json = wp_unslash( $_POST['fwb_beladung'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$roh  = json_decode( is_string( $json ) ? $json : '', true );
		if ( ! is_array( $roh ) ) {
			self::hinweis_merken( array( 'Die Beladung konnte nicht gelesen werden und wurde nicht verändert.' ) );
			return;
		}
		$ergebnis = Beladung::speichern( $post_id, $roh );
		if ( $ergebnis['geaendert'] ) {
			Protokoll::eintragen( 'fahrzeug', $post_id, 'Beladung geändert', '„' . $post->post_title . '“: ' . Schema::zusammenfassung( $ergebnis['dokument'] ) );
		}
		if ( $ergebnis['hinweise'] ) {
			self::hinweis_merken( $ergebnis['hinweise'] );
		}
	}

	private static function hinweis_merken( array $hinweise ): void {
		set_transient( 'fwb_editor_hinweise_' . get_current_user_id(), $hinweise, 120 );
	}

	public static function hinweise_ausgeben(): void {
		$schluessel = 'fwb_editor_hinweise_' . get_current_user_id();
		$hinweise   = get_transient( $schluessel );
		if ( ! is_array( $hinweise ) || ! $hinweise ) {
			return;
		}
		delete_transient( $schluessel );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Beim Speichern wurde Folgendes korrigiert:</strong></p><ul style="list-style:disc;padding-left:20px">';
		foreach ( $hinweise as $h ) {
			echo '<li>' . esc_html( (string) $h ) . '</li>';
		}
		echo '</ul></div>';
	}
}
