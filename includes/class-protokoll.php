<?php
/**
 * Protokoll: wer hat wann was geändert.
 *
 * Eigene Tabelle, damit Einträge auch nach dem Löschen eines Fahrzeugs
 * erhalten bleiben. Es werden höchstens MAX_EINTRAEGE Zeilen behalten.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Protokoll {

	public const MAX_EINTRAEGE = 2000;

	/** Wenn das Plugin selbst Beiträge anlegt, schreibt der Aufrufer einen Sammeleintrag. */
	private static int $stumm = 0;

	/**
	 * Führt $aufgabe aus, ohne dass Statuswechsel einzeln protokolliert werden.
	 *
	 * @template T
	 * @param callable():T $aufgabe
	 * @return T
	 */
	public static function ohne_statuseintraege( callable $aufgabe ) {
		++self::$stumm;
		try {
			return $aufgabe();
		} finally {
			--self::$stumm;
		}
	}

	public static function tabelle(): string {
		global $wpdb;
		return $wpdb->prefix . 'fwb_protokoll';
	}

	public static function tabelle_anlegen(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tabelle = self::tabelle();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$tabelle} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				zeit datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				objekt_typ varchar(20) NOT NULL DEFAULT '',
				objekt_id bigint(20) unsigned NOT NULL DEFAULT 0,
				aktion varchar(30) NOT NULL DEFAULT '',
				text varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY zeit (zeit)
			) {$charset};"
		);
	}

	public static function haken(): void {
		add_action( 'transition_post_status', array( self::class, 'status_wechsel' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'endgueltig_geloescht' ), 10, 2 );
	}

	public static function eintragen( string $objekt_typ, int $objekt_id, string $aktion, string $text ): void {
		global $wpdb;
		$wpdb->insert(
			self::tabelle(),
			array(
				'zeit'       => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'objekt_typ' => mb_substr( $objekt_typ, 0, 20 ),
				'objekt_id'  => $objekt_id,
				'aktion'     => mb_substr( $aktion, 0, 30 ),
				'text'       => mb_substr( $text, 0, 255 ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		// Gelegentlich aufräumen.
		if ( 0 === wp_rand( 0, 49 ) ) {
			$tabelle = self::tabelle();
			$grenze  = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$tabelle}" ) - self::MAX_EINTRAEGE; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $grenze > 0 ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$tabelle} WHERE id <= %d", $grenze ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	/** @return array<int, object> */
	public static function letzte( int $anzahl = 200 ): array {
		global $wpdb;
		$tabelle = self::tabelle();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabelle} ORDER BY id DESC LIMIT %d", $anzahl ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function status_wechsel( string $neu, string $alt, \WP_Post $post ): void {
		$typen = array( Fahrzeug_Typ::TYP => 'fahrzeug', Geraete_Katalog::TYP => 'geraet' );
		if ( self::$stumm > 0 || ! isset( $typen[ $post->post_type ] ) || $neu === $alt || 'auto-draft' === $neu ) {
			return;
		}
		$art   = Fahrzeug_Typ::TYP === $post->post_type ? 'Fahrzeug' : 'Gerät';
		$titel = '' !== $post->post_title ? $post->post_title : '(ohne Namen)';
		if ( 'trash' === $neu ) {
			$aktion = 'Papierkorb';
		} elseif ( 'trash' === $alt ) {
			$aktion = 'wiederhergestellt';
		} elseif ( 'publish' === $neu ) {
			$aktion = 'veröffentlicht';
		} elseif ( in_array( $alt, array( 'new', 'auto-draft' ), true ) ) {
			$aktion = 'angelegt';
		} elseif ( 'publish' === $alt ) {
			$aktion = 'zurückgezogen';
		} else {
			$aktion = 'Status ' . $neu;
		}
		self::eintragen( $typen[ $post->post_type ], $post->ID, $aktion, $art . ' „' . $titel . '“' );
	}

	public static function endgueltig_geloescht( int $post_id, \WP_Post $post ): void {
		$typen = array( Fahrzeug_Typ::TYP => 'fahrzeug', Geraete_Katalog::TYP => 'geraet' );
		if ( isset( $typen[ $post->post_type ] ) ) {
			self::eintragen( $typen[ $post->post_type ], $post_id, 'gelöscht', 'Endgültig gelöscht: „' . $post->post_title . '“' );
		}
	}

	/** Admin-Seite. */
	public static function seite(): void {
		if ( ! current_user_can( Rechte::BEARBEITEN ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		$eintraege = self::letzte( 200 );
		echo '<div class="wrap"><h1>Protokoll</h1>';
		echo '<p>Die letzten 200 Änderungen an Fahrzeugen, Geräten und Einstellungen.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Zeit</th><th>Wer</th><th>Was</th><th>Aktion</th></tr></thead><tbody>';
		if ( ! $eintraege ) {
			echo '<tr><td colspan="4">Noch keine Einträge.</td></tr>';
		}
		foreach ( $eintraege as $e ) {
			$user = get_userdata( (int) $e->user_id );
			$zeit = get_date_from_gmt( $e->zeit, 'd.m.Y H:i' );
			$text = esc_html( $e->text );
			if ( in_array( $e->objekt_typ, array( 'fahrzeug', 'geraet' ), true ) && get_post( (int) $e->objekt_id ) ) {
				$text = '<a href="' . esc_url( (string) get_edit_post_link( (int) $e->objekt_id ) ) . '">' . $text . '</a>';
			}
			echo '<tr><td>' . esc_html( $zeit ) . '</td><td>' . esc_html( $user ? $user->display_name : '–' ) . '</td><td>' . $text . '</td><td>' . esc_html( $e->aktion ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $text ist oben escaped.
		}
		echo '</tbody></table></div>';
	}
}
