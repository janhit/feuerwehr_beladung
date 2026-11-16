<?php
/**
 * Wird nur beim Löschen des Plugins ausgeführt (nicht bei Updates und nicht
 * beim Deaktivieren). Daten werden nur entfernt, wenn ein Administrator das
 * in den Einstellungen ausdrücklich eingeschaltet hat.
 *
 * @package FeuerwehrBeladung
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$fwb_einstellungen = get_option( 'fwb_einstellungen', array() );
if ( empty( $fwb_einstellungen['daten_beim_loeschen_entfernen'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'fwb_fahrzeug', 'fwb_geraet' ) as $fwb_typ ) {
	$fwb_ids = get_posts(
		array(
			'post_type'      => $fwb_typ,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $fwb_ids as $fwb_id ) {
		wp_delete_post( $fwb_id, true );
	}
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fwb_protokoll" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( wp_roles()->role_objects as $fwb_rolle ) {
	$fwb_rolle->remove_cap( 'fwb_bearbeiten' );
	$fwb_rolle->remove_cap( 'fwb_verwalten' );
}

delete_option( 'fwb_einstellungen' );
delete_option( 'fwb_db_stand' );
