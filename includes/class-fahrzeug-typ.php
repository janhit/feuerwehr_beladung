<?php
/**
 * Beitragstyp "Fahrzeug". Nicht öffentlich: keine eigene Adresse,
 * nicht in der Suche, nicht in der REST-API von WordPress.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Fahrzeug_Typ {

	public const TYP = 'fwb_fahrzeug';

	public static function registrieren(): void {
		register_post_type(
			self::TYP,
			array(
				'labels'              => array(
					'name'               => 'Fahrzeuge',
					'singular_name'      => 'Fahrzeug',
					'menu_name'          => 'Beladung',
					'all_items'          => 'Fahrzeuge',
					'add_new'            => 'Neues Fahrzeug',
					'add_new_item'       => 'Neues Fahrzeug anlegen',
					'edit_item'          => 'Fahrzeug bearbeiten',
					'new_item'           => 'Neues Fahrzeug',
					'search_items'       => 'Fahrzeuge durchsuchen',
					'not_found'          => 'Noch keine Fahrzeuge angelegt.',
					'not_found_in_trash' => 'Keine Fahrzeuge im Papierkorb.',
					'item_published'     => 'Fahrzeug veröffentlicht.',
					'item_updated'       => 'Fahrzeug gespeichert.',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-car',
				'supports'            => array( 'title' ),
				'capabilities'        => Rechte::beitragstyp_rechte(),
				'map_meta_cap'        => false,
			)
		);
	}

	public static function haken(): void {
		add_filter( 'manage_' . self::TYP . '_posts_columns', array( self::class, 'spalten' ) );
		add_action( 'manage_' . self::TYP . '_posts_custom_column', array( self::class, 'spalte_ausgeben' ), 10, 2 );
		add_filter( 'post_updated_messages', array( self::class, 'meldungen' ) );
	}

	public static function spalten( array $spalten ): array {
		$neu = array();
		foreach ( $spalten as $schluessel => $titel ) {
			if ( 'date' === $schluessel ) {
				$neu['fwb_inhalt']   = 'Inhalt';
				$neu['fwb_geaendert'] = 'Zuletzt geändert';
				continue;
			}
			$neu[ $schluessel ] = $titel;
		}
		return $neu;
	}

	public static function spalte_ausgeben( string $spalte, int $post_id ): void {
		if ( 'fwb_inhalt' === $spalte ) {
			echo esc_html( Schema::zusammenfassung( Beladung::lesen( $post_id ) ) );
		}
		if ( 'fwb_geaendert' === $spalte ) {
			$user_id = (int) get_post_meta( $post_id, '_edit_last', true );
			$user    = $user_id ? get_userdata( $user_id ) : null;
			echo esc_html( get_the_modified_date( 'd.m.Y H:i', $post_id ) );
			if ( $user ) {
				echo '<br>' . esc_html( 'von ' . $user->display_name );
			}
		}
	}

	public static function meldungen( array $meldungen ): array {
		$meldungen[ self::TYP ] = array(
			1  => 'Fahrzeug gespeichert.',
			4  => 'Fahrzeug gespeichert.',
			6  => 'Fahrzeug veröffentlicht. Mitglieder sehen es jetzt auf der Lernseite.',
			7  => 'Fahrzeug gespeichert.',
			8  => 'Fahrzeug eingereicht.',
			10 => 'Entwurf gespeichert. Mitglieder sehen das Fahrzeug noch nicht.',
		);
		return $meldungen;
	}
}
