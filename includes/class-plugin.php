<?php
/**
 * Startpunkt: verbindet alle Teile mit WordPress.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function starten(): void {
		add_action( 'plugins_loaded', array( Installation::class, 'aktualisieren_falls_noetig' ) );
		add_action( 'init', array( Fahrzeug_Typ::class, 'registrieren' ) );
		add_action( 'init', array( Geraete_Katalog::class, 'registrieren' ) );

		Fahrzeug_Typ::haken();
		Geraete_Katalog::haken();
		Protokoll::haken();
		Rest::haken();

		if ( is_admin() ) {
			Admin_Menue::haken();
			Editor::haken();
			Beispiel::haken();
			Einstellungen::haken();
		}
	}

	/**
	 * Darf der aktuelle Nutzer die Lernseite sehen? Alle angemeldeten Mitglieder.
	 */
	public static function darf_lernen(): bool {
		return (bool) apply_filters( 'fwb_darf_lernen', is_user_logged_in() );
	}

	/**
	 * Versionsnummer für CSS/JS. Im Testbetrieb (WP_DEBUG) das Änderungsdatum,
	 * damit Browser keine alten Dateien aus dem Zwischenspeicher nehmen.
	 */
	public static function datei_version( string $relativer_pfad ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$zeit = @filemtime( FWB_PFAD . $relativer_pfad ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $zeit ) {
				return FWB_VERSION . '-' . $zeit;
			}
		}
		return FWB_VERSION;
	}

	public static function darf_bearbeiten(): bool {
		return current_user_can( Rechte::BEARBEITEN );
	}

	public static function darf_verwalten(): bool {
		return current_user_can( Rechte::VERWALTEN );
	}
}
