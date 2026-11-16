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
		add_action( 'admin_post_fwb_selbsttest', array( self::class, 'selbsttest' ) );
		add_action( 'admin_post_fwb_lernseite_anlegen', array( self::class, 'lernseite_anlegen' ) );
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
		echo '</form>';
		self::bereich_lernseite();
		self::bereich_fotoschutz();
		echo '</div>';
	}

	private static function bereich_lernseite(): void {
		echo '<h2>Lernseite für Mitglieder</h2>';
		echo '<p>Die Lernseite erscheint überall dort, wo der Shortcode <code>[' . esc_html( Lernseite::SHORTCODE ) . ']</code> steht. Nur angemeldete Mitglieder sehen die Fahrzeuge; alle anderen sehen einen Anmeldeknopf.</p>';
		$seiten = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				's'              => '[' . Lernseite::SHORTCODE,
				'posts_per_page' => 5,
			)
		);
		if ( $seiten ) {
			echo '<p>Seiten mit Lernseite:</p><ul style="list-style:disc;padding-left:20px">';
			foreach ( $seiten as $seite ) {
				$status = 'publish' === $seite->post_status ? '' : ' (' . ( 'draft' === $seite->post_status ? 'Entwurf' : 'privat' ) . ')';
				echo '<li><a href="' . esc_url( (string) get_permalink( $seite ) ) . '">' . esc_html( get_the_title( $seite ) ) . '</a>' . esc_html( $status ) . ' – <a href="' . esc_url( (string) get_edit_post_link( $seite->ID ) ) . '">bearbeiten</a></li>';
			}
			echo '</ul>';
		} elseif ( current_user_can( 'publish_pages' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="fwb_lernseite_anlegen">';
			wp_nonce_field( 'fwb_lernseite_anlegen' );
			submit_button( 'Lernseite als Entwurf anlegen', 'secondary', 'submit', false );
			echo ' <span class="description">Legt eine Seite „Beladung lernen“ mit dem Shortcode an. Du veröffentlichst sie selbst und kannst sie ins Menü aufnehmen.</span></form>';
		}
	}

	private static function bereich_fotoschutz(): void {
		echo '<h2>Fotoschutz</h2>';
		echo '<p>Fotos, die im Fahrzeug-Editor hochgeladen werden, verschiebt das Plugin beim Speichern in den Ordner <code>uploads/' . esc_html( Fotoschutz::ORDNER ) . '</code> mit zufälligen Unterordnern. Ausgeliefert werden sie nur an angemeldete Nutzer. Geschützte Fotos: <strong>' . (int) Fotoschutz::anzahl() . '</strong>.</p>';

		$test = get_transient( 'fwb_selbsttest_' . get_current_user_id() );
		if ( is_array( $test ) ) {
			delete_transient( 'fwb_selbsttest_' . get_current_user_id() );
			echo '<div class="notice inline ' . ( $test['auslieferung_gesperrt'] && $test['ordner_gesperrt'] ? 'notice-success' : 'notice-warning' ) . '"><p><strong>Ergebnis des Selbsttests</strong></p><ul style="list-style:disc;padding-left:20px">';
			if ( true === $test['auslieferung_gesperrt'] ) {
				echo '<li>✓ Ohne Anmeldung liefert das Plugin keine Fotos aus.</li>';
			} elseif ( false === $test['auslieferung_gesperrt'] ) {
				echo '<li><strong>✗ Das Plugin liefert ohne Anmeldung aus.</strong> Bitte melden – das darf nicht sein.</li>';
			}
			if ( true === $test['ordner_gesperrt'] ) {
				echo '<li>✓ Der geschützte Ordner ist von außen gesperrt.</li>';
			} elseif ( false === $test['ordner_gesperrt'] ) {
				echo '<li><strong>Der Ordner ist von außen direkt erreichbar</strong> (der Webserver beachtet die Sperrdatei <code>.htaccess</code> nicht, typisch für nginx). '
					. 'Die Unterordner haben zufällige Namen mit 32 Zeichen und erscheinen nirgends – erraten lassen sie sich praktisch nicht. '
					. 'Für vollständigen Schutz bitte beim Hoster bzw. in der nginx-Konfiguration ergänzen:<br><code>location ^~ /wp-content/uploads/' . esc_html( Fotoschutz::ORDNER ) . '/ { deny all; }</code></li>';
			}
			foreach ( $test['hinweise'] as $h ) {
				echo '<li>' . esc_html( $h ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fwb_selbsttest">';
		wp_nonce_field( 'fwb_selbsttest' );
		submit_button( 'Selbsttest starten', 'secondary', 'submit', false );
		echo ' <span class="description">Prüft von außen (ohne Anmeldung), ob Fotos abrufbar sind.</span></form>';
	}

	public static function selbsttest(): void {
		if ( ! current_user_can( Rechte::VERWALTEN ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		check_admin_referer( 'fwb_selbsttest' );
		set_transient( 'fwb_selbsttest_' . get_current_user_id(), Fotoschutz::selbsttest(), 300 );
		wp_safe_redirect( admin_url( Admin_Menue::ELTERN . '&page=fwb-einstellungen' ) );
		exit;
	}

	public static function lernseite_anlegen(): void {
		if ( ! current_user_can( Rechte::VERWALTEN ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		check_admin_referer( 'fwb_lernseite_anlegen' );
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Beladung lernen',
				'post_content' => '<!-- wp:shortcode -->[' . Lernseite::SHORTCODE . ']<!-- /wp:shortcode -->',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			Protokoll::eintragen( 'einstellung', (int) $id, 'angelegt', 'Seite „Beladung lernen“ als Entwurf angelegt' );
			wp_safe_redirect( (string) get_edit_post_link( (int) $id, 'raw' ) );
			exit;
		}
		wp_die( esc_html( 'Die Seite konnte nicht angelegt werden.' ) );
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
