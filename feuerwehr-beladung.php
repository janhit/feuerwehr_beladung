<?php
/**
 * Plugin Name:       Feuerwehr Beladung
 * Plugin URI:        https://github.com/janhit/feuerwehr_beladung
 * Description:       Mitglieder lernen am Handy, welches Gerät in welchem Gerätefach der Fahrzeuge liegt.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Freiwillige Feuerwehr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       feuerwehr-beladung
 * Update URI:        https://github.com/janhit/feuerwehr_beladung
 */

defined( 'ABSPATH' ) || exit;

define( 'FWB_VERSION', '0.1.0' );
define( 'FWB_DATEI', __FILE__ );
define( 'FWB_PFAD', plugin_dir_path( __FILE__ ) );
define( 'FWB_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html( 'Feuerwehr Beladung braucht mindestens PHP 8.1. Auf diesem Server läuft PHP ' . PHP_VERSION . '. Bitte beim Hoster eine neuere PHP-Version einstellen.' );
			echo '</p></div>';
		}
	);
	return;
}

/*
 * Einfacher Autoloader: FWB\Geraete_Katalog → includes/class-geraete-katalog.php
 */
spl_autoload_register(
	static function ( string $klasse ): void {
		if ( ! str_starts_with( $klasse, 'FWB\\' ) ) {
			return;
		}
		$name  = strtolower( str_replace( '_', '-', substr( $klasse, 4 ) ) );
		$datei = FWB_PFAD . 'includes/class-' . $name . '.php';
		if ( is_readable( $datei ) ) {
			require $datei;
		}
	}
);

register_activation_hook( __FILE__, array( 'FWB\\Installation', 'aktivieren' ) );
register_deactivation_hook( __FILE__, array( 'FWB\\Installation', 'deaktivieren' ) );

FWB\Plugin::starten();
