<?php
/**
 * Rechte des Plugins.
 *
 * Lernen: alle angemeldeten Nutzer (siehe Plugin::darf_lernen).
 * Bearbeiten: Redakteure und Administratoren.
 * Verwalten (Einstellungen, Update, Lernstände löschen): nur Administratoren.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Rechte {

	public const BEARBEITEN = 'fwb_bearbeiten';
	public const VERWALTEN  = 'fwb_verwalten';

	/** Rolle => Rechte, die sie bekommt. */
	private const ZUORDNUNG = array(
		'administrator' => array( self::BEARBEITEN, self::VERWALTEN ),
		'editor'        => array( self::BEARBEITEN ),
	);

	public static function vergeben(): void {
		foreach ( self::ZUORDNUNG as $rolle_name => $rechte ) {
			$rolle = get_role( $rolle_name );
			if ( ! $rolle ) {
				continue;
			}
			foreach ( $rechte as $recht ) {
				$rolle->add_cap( $recht );
			}
		}
	}

	public static function entfernen(): void {
		foreach ( wp_roles()->role_objects as $rolle ) {
			$rolle->remove_cap( self::BEARBEITEN );
			$rolle->remove_cap( self::VERWALTEN );
		}
	}

	/**
	 * Capabilities für register_post_type: alles hängt an "fwb_bearbeiten".
	 */
	public static function beitragstyp_rechte(): array {
		$r = self::BEARBEITEN;
		return array(
			'edit_post'              => $r,
			'read_post'              => $r,
			'delete_post'            => $r,
			'edit_posts'             => $r,
			'edit_others_posts'      => $r,
			'edit_published_posts'   => $r,
			'edit_private_posts'     => $r,
			'publish_posts'          => $r,
			'read_private_posts'     => $r,
			'delete_posts'           => $r,
			'delete_others_posts'    => $r,
			'delete_published_posts' => $r,
			'delete_private_posts'   => $r,
			'create_posts'           => $r,
			'read'                   => 'read',
		);
	}
}
