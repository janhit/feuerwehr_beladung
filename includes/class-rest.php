<?php
/**
 * Eigene REST-Schnittstelle. Jede Route prüft die Rechte selbst.
 *
 * - /geraete            Gerätekatalog für den Editor (Bearbeiter)
 * - /fotos/<id>/schuetzen Foto in den geschützten Ordner verschieben (Bearbeiter)
 * - /fahrzeuge          Lernseite: Übersicht (angemeldete Mitglieder)
 * - /fahrzeuge/<id>     Lernseite: ein Fahrzeug (angemeldete Mitglieder)
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NAMENSRAUM = 'fwb/v1';

	public static function haken(): void {
		add_action( 'rest_api_init', array( self::class, 'routen' ) );
		add_action( 'rest_api_init', array( self::class, 'lern_routen' ) );
	}

	public static function routen(): void {
		register_rest_route(
			self::NAMENSRAUM,
			'/geraete',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'geraete_suchen' ),
					'permission_callback' => array( Plugin::class, 'darf_bearbeiten' ),
					'args'                => array(
						'suche' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'geraet_anlegen' ),
					'permission_callback' => array( Plugin::class, 'darf_bearbeiten' ),
					'args'                => array(
						'name'         => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'beschreibung' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);
	}

	public static function lern_routen(): void {
		register_rest_route(
			self::NAMENSRAUM,
			'/fahrzeuge',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => new \WP_REST_Response( Lern_Daten::fahrzeuge() ),
				'permission_callback' => array( Plugin::class, 'darf_lernen' ),
			)
		);
		register_rest_route(
			self::NAMENSRAUM,
			'/fahrzeuge/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'fahrzeug' ),
				'permission_callback' => array( Plugin::class, 'darf_lernen' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NAMENSRAUM,
			'/fotos/(?P<id>\d+)/schuetzen',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'foto_schuetzen' ),
				'permission_callback' => static fn( \WP_REST_Request $a ) => Plugin::darf_bearbeiten() && current_user_can( 'upload_files' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	public static function fahrzeug( \WP_REST_Request $anfrage ) {
		$daten = Lern_Daten::fahrzeug( (int) $anfrage['id'] );
		if ( null === $daten ) {
			return new \WP_Error( 'fwb_nicht_gefunden', 'Dieses Fahrzeug gibt es nicht (mehr).', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $daten );
	}

	public static function foto_schuetzen( \WP_REST_Request $anfrage ) {
		$id       = (int) $anfrage['id'];
		$ergebnis = Fotoschutz::schuetzen( $id );
		if ( is_wp_error( $ergebnis ) ) {
			$ergebnis->add_data( array( 'status' => 400 ) );
			return $ergebnis;
		}
		Protokoll::eintragen( 'foto', $id, 'geschützt', 'Foto „' . get_the_title( $id ) . '“ in den geschützten Ordner verschoben' );
		$bild = wp_get_attachment_image_src( $id, 'large' );
		return new \WP_REST_Response(
			array(
				'url'        => $bild ? $bild[0] : '',
				'b'          => $bild ? $bild[1] : 0,
				'h'          => $bild ? $bild[2] : 0,
				'geschuetzt' => true,
			)
		);
	}

	public static function geraete_suchen( \WP_REST_Request $anfrage ): \WP_REST_Response {
		return new \WP_REST_Response( array_map( array( self::class, 'kurz' ), Geraete_Katalog::suchen( (string) $anfrage['suche'], 30 ) ) );
	}

	public static function geraet_anlegen( \WP_REST_Request $anfrage ) {
		$name = trim( (string) $anfrage['name'] );
		if ( '' === $name ) {
			return new \WP_Error( 'fwb_name_fehlt', 'Bitte einen Namen eingeben.', array( 'status' => 400 ) );
		}
		$schon_da = null;
		foreach ( Geraete_Katalog::alle() as $g ) {
			if ( 0 === strcasecmp( $g['name'], $name ) ) {
				$schon_da = $g;
			}
		}
		$uid = Geraete_Katalog::anlegen( $name, (string) $anfrage['beschreibung'] );
		if ( '' === $uid ) {
			return new \WP_Error( 'fwb_fehler', 'Das Gerät konnte nicht angelegt werden.', array( 'status' => 500 ) );
		}
		if ( ! $schon_da ) {
			Protokoll::eintragen( 'geraet', Geraete_Katalog::alle()[ $uid ]['id'] ?? 0, 'angelegt', 'Gerät „' . $name . '“ im Editor angelegt' );
		}
		return new \WP_REST_Response(
			array(
				'geraet'   => self::kurz( Geraete_Katalog::alle()[ $uid ] ),
				'schon_da' => (bool) $schon_da,
			),
			$schon_da ? 200 : 201
		);
	}

	private static function kurz( array $g ): array {
		return array(
			'uid'          => $g['uid'],
			'name'         => $g['name'],
			'beschreibung' => wp_trim_words( $g['beschreibung'], 20 ),
			'bearbeiten'   => (string) get_edit_post_link( $g['id'], 'raw' ),
		);
	}
}
