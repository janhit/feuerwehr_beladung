<?php
/**
 * Lesen und Speichern der Beladung eines Fahrzeugs (Meta-Feld als JSON),
 * mit den letzten Ständen zum Wiederherstellen.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Beladung {

	public const META           = '_fwb_beladung';
	public const META_VERSIONEN = '_fwb_versionen';
	public const MAX_VERSIONEN  = 10;

	public static function lesen( int $fahrzeug_id ): array {
		$json = get_post_meta( $fahrzeug_id, self::META, true );
		$dok  = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		return is_array( $dok ) ? $dok : Schema::leer();
	}

	/**
	 * Bereinigt und speichert. Der vorherige Stand kommt in die Versionsliste.
	 *
	 * @return array{dokument: array, hinweise: string[], geaendert: bool}
	 */
	public static function speichern( int $fahrzeug_id, $roh ): array {
		[ $dok, $hinweise ] = Schema::bereinigen( $roh );

		$alt_json = (string) get_post_meta( $fahrzeug_id, self::META, true );
		$neu_json = (string) wp_json_encode( $dok, JSON_UNESCAPED_UNICODE );

		if ( $alt_json === $neu_json ) {
			return array( 'dokument' => $dok, 'hinweise' => $hinweise, 'geaendert' => false );
		}

		if ( '' !== $alt_json ) {
			$versionen = self::versionen( $fahrzeug_id );
			array_unshift(
				$versionen,
				array(
					'zeit'    => time(),
					'user_id' => get_current_user_id(),
					'json'    => $alt_json,
				)
			);
			update_post_meta( $fahrzeug_id, self::META_VERSIONEN, wp_slash( array_slice( $versionen, 0, self::MAX_VERSIONEN ) ) );
		}

		// update_post_meta entfernt Backslashes; wp_slash schützt das JSON davor.
		update_post_meta( $fahrzeug_id, self::META, wp_slash( $neu_json ) );

		return array( 'dokument' => $dok, 'hinweise' => $hinweise, 'geaendert' => true );
	}

	/** @return array<int, array{zeit:int, user_id:int, json:string}> */
	public static function versionen( int $fahrzeug_id ): array {
		$v = get_post_meta( $fahrzeug_id, self::META_VERSIONEN, true );
		return is_array( $v ) ? array_values( $v ) : array();
	}

	/** IDs aller Fahrzeuge (jeder Status außer automatischen Entwürfen). */
	public static function alle_fahrzeug_ids(): array {
		return get_posts(
			array(
				'post_type'      => Fahrzeug_Typ::TYP,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}
}
