<?php
/**
 * Lädt ein echtes WordPress für die Tests.
 *
 * WP_ROOT zeigt auf die WordPress-Installation (Standard: /var/www/html).
 * Die Tests legen Beiträge an und räumen sie wieder auf. Bitte eine eigene
 * Test-Datenbank verwenden, nie die echte Seite.
 *
 * @package FeuerwehrBeladung
 */

$fwb_wurzel = rtrim( (string) ( getenv( 'WP_ROOT' ) ?: '/var/www/html' ), '/' );

if ( ! file_exists( $fwb_wurzel . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress nicht gefunden unter {$fwb_wurzel}. WP_ROOT setzen.\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

require $fwb_wurzel . '/wp-load.php';

// Plugin laden, falls es in dieser Installation nicht aktiv ist.
if ( ! class_exists( 'FWB\\Plugin' ) ) {
	require dirname( __DIR__, 2 ) . '/feuerwehr-beladung.php';
}
FWB\Installation::aktivieren();
FWB\Fahrzeug_Typ::registrieren();
FWB\Geraete_Katalog::registrieren();
do_action( 'rest_api_init', rest_get_server() );
