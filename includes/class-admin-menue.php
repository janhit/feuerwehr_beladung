<?php
/**
 * Untermenüs unter "Beladung".
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Admin_Menue {

	public const ELTERN = 'edit.php?post_type=fwb_fahrzeug';

	public static function haken(): void {
		add_action( 'admin_menu', array( self::class, 'menue' ) );
	}

	public static function menue(): void {
		add_submenu_page( self::ELTERN, 'Protokoll', 'Protokoll', Rechte::BEARBEITEN, 'fwb-protokoll', array( Protokoll::class, 'seite' ) );
		add_submenu_page( self::ELTERN, 'Einstellungen', 'Einstellungen', Rechte::VERWALTEN, 'fwb-einstellungen', array( Einstellungen::class, 'seite' ) );
	}
}
