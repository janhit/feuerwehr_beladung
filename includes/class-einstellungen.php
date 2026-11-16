<?php
/**
 * Einstellungen (nur Administratoren).
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Einstellungen {

	public const OPTION = 'fwb_einstellungen';

	private const STANDARD = array(
		'daten_beim_loeschen_entfernen' => false,
	);

	public static function haken(): void {
		add_action( 'admin_post_fwb_einstellungen', array( self::class, 'speichern' ) );
	}

	public static function alle(): array {
		$werte = get_option( self::OPTION, array() );
		return array_merge( self::STANDARD, is_array( $werte ) ? $werte : array() );
	}

	public static function wert( string $schluessel ) {
		return self::alle()[ $schluessel ] ?? null;
	}

	public static function seite(): void {
		if ( ! current_user_can( Rechte::VERWALTEN ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		$e = self::alle();
		echo '<div class="wrap"><h1>Einstellungen: Feuerwehr Beladung</h1>';
		if ( isset( $_GET['gespeichert'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fwb_einstellungen">';
		wp_nonce_field( 'fwb_einstellungen' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row">Beim Löschen des Plugins</th><td>';
		echo '<label><input type="checkbox" name="daten_beim_loeschen_entfernen" value="1" ' . checked( $e['daten_beim_loeschen_entfernen'], true, false ) . '> ';
		echo 'Alle Fahrzeuge, Geräte und das Protokoll ebenfalls löschen</label>';
		echo '<p class="description">Ausgeschaltet (empfohlen) bleiben alle Daten erhalten, auch wenn das Plugin gelöscht wird. Updates löschen nie Daten.</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">Version und Updates</th><td>' . Updater::stand_html() . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in stand_html() maskiert.
		echo '</table>';
		submit_button( 'Speichern' );
		echo '</form></div>';
	}

	public static function speichern(): void {
		if ( ! current_user_can( Rechte::VERWALTEN ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		check_admin_referer( 'fwb_einstellungen' );
		$neu = array(
			'daten_beim_loeschen_entfernen' => ! empty( $_POST['daten_beim_loeschen_entfernen'] ),
		);
		update_option( self::OPTION, $neu, false );
		Protokoll::eintragen( 'einstellung', 0, 'geändert', 'Einstellungen gespeichert' );
		wp_safe_redirect( admin_url( Admin_Menue::ELTERN . '&page=fwb-einstellungen&gespeichert=1' ) );
		exit;
	}
}
