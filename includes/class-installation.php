<?php
/**
 * Aktivierung und Datenbank-Stand.
 *
 * Wichtig: Updates lösen keine Aktivierung aus. Deshalb prüft
 * aktualisieren_falls_noetig() bei jedem Laden den gespeicherten Stand.
 * Bestehende Daten werden dabei nie gelöscht, nur ergänzt.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Installation {

	public const DB_STAND        = 1;
	private const OPTION_DB_STAND = 'fwb_db_stand';

	public static function aktivieren(): void {
		self::einrichten();
		Fahrzeug_Typ::registrieren();
		Geraete_Katalog::registrieren();
		flush_rewrite_rules();
	}

	public static function deaktivieren(): void {
		flush_rewrite_rules();
	}

	public static function aktualisieren_falls_noetig(): void {
		if ( (int) get_option( self::OPTION_DB_STAND, 0 ) < self::DB_STAND ) {
			self::einrichten();
		}
	}

	private static function einrichten(): void {
		Rechte::vergeben();
		Protokoll::tabelle_anlegen();
		update_option( self::OPTION_DB_STAND, self::DB_STAND, false );
	}
}
