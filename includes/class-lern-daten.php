<?php
/**
 * Bereitet Fahrzeuge für die Lernseite auf: nur, was Mitglieder sehen sollen,
 * mit fertigen Bildadressen und den Texten aus dem Gerätekatalog.
 *
 * Mitglieder sehen nur veröffentlichte Fahrzeuge. Wer bearbeiten darf, sieht
 * zusätzlich Entwürfe (zum Prüfen vor dem Veröffentlichen).
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Lern_Daten {

	/** @return string[] Status, die der aktuelle Nutzer sehen darf. */
	private static function sichtbare_status(): array {
		return Plugin::darf_bearbeiten() ? array( 'publish', 'draft', 'pending', 'future' ) : array( 'publish' );
	}

	/** Übersicht aller Fahrzeuge. */
	public static function fahrzeuge(): array {
		$posts = get_posts(
			array(
				'post_type'      => Fahrzeug_Typ::TYP,
				'post_status'    => self::sichtbare_status(),
				'posts_per_page' => 100,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			)
		);
		$liste = array();
		foreach ( $posts as $p ) {
			$dok     = Beladung::lesen( $p->ID );
			$geraete = 0;
			foreach ( $dok['faecher'] as $f ) {
				$geraete += count( $f['plaetze'] );
			}
			$eckdaten = array();
			foreach ( $dok['steckbrief']['felder'] as $feld ) {
				if ( $feld['eckdatum'] && '' !== $feld['wert'] ) {
					$eckdaten[] = array( 'label' => $feld['label'], 'wert' => $feld['wert'] );
				}
			}
			$foto = self::bild( (int) $dok['steckbrief']['foto_id'], '', 4 / 3 );
			if ( ! $foto && $dok['ansichten'] ) {
				$foto = self::bild( (int) $dok['ansichten'][0]['foto_id'], $dok['ansichten'][0]['skizze'], 3 );
			}
			$liste[] = array(
				'id'       => $p->ID,
				'titel'    => get_the_title( $p ),
				'entwurf'  => 'publish' !== $p->post_status,
				'foto'     => $foto,
				'kurz'     => $dok['steckbrief']['kurz'],
				'eckdaten' => $eckdaten,
				'faecher'  => count( $dok['faecher'] ),
				'geraete'  => $geraete,
			);
		}
		return $liste;
	}

	/** Ein Fahrzeug mit allem, was die Lernseite braucht – oder null. */
	public static function fahrzeug( int $id ): ?array {
		$p = get_post( $id );
		if ( ! $p || Fahrzeug_Typ::TYP !== $p->post_type || ! in_array( $p->post_status, self::sichtbare_status(), true ) ) {
			return null;
		}
		$dok     = Beladung::lesen( $id );
		$katalog = Geraete_Katalog::alle();

		$geraete = array();
		foreach ( Schema::geraete_uids( $dok ) as $uid ) {
			if ( isset( $katalog[ $uid ] ) ) {
				$g               = $katalog[ $uid ];
				$geraete[ $uid ] = array(
					'name'         => $g['name'],
					'beschreibung' => $g['beschreibung'],
					'hinweise'     => $g['hinweise'],
					'suchbegriffe' => $g['suchbegriffe'],
				);
			}
		}

		$felder = array();
		foreach ( $dok['steckbrief']['felder'] as $feld ) {
			if ( '' !== $feld['wert'] ) {
				$felder[] = array( 'label' => $feld['label'], 'wert' => $feld['wert'] );
			}
		}

		$ansichten = array();
		foreach ( $dok['ansichten'] as $a ) {
			$ansichten[] = array(
				'id'       => $a['id'],
				'name'     => $a['name'],
				'bild'     => self::bild( (int) $a['foto_id'], $a['skizze'], 3 ),
				'bereiche' => array_map(
					static fn( $r ) => array( 'fach_id' => $r['fach_id'], 'x' => $r['x'], 'y' => $r['y'], 'b' => $r['b'], 'h' => $r['h'] ),
					$a['bereiche']
				),
			);
		}

		$faecher = array();
		foreach ( $dok['faecher'] as $f ) {
			$bilder = array();
			foreach ( $f['bilder'] as $b ) {
				$bilder[] = array(
					'id'           => $b['id'],
					'titel'        => $b['titel'],
					'bild'         => self::bild( (int) $b['foto_id'], $b['skizze'], 4 / 3 ),
					'markierungen' => array_map(
						static fn( $m ) => array( 'platz_id' => $m['platz_id'], 'x' => $m['x'], 'y' => $m['y'], 'b' => $m['b'], 'h' => $m['h'], 'notiz' => $m['notiz'] ?? '' ),
						$b['markierungen']
					),
					'schalter'     => array_map(
						static fn( $s ) => array( 'art' => $s['art'], 'ziel_bild_id' => $s['ziel_bild_id'], 'x' => $s['x'], 'y' => $s['y'] ),
						$b['schalter']
					),
				);
			}
			$plaetze = array();
			foreach ( $f['plaetze'] as $pl ) {
				if ( isset( $geraete[ $pl['geraet'] ] ) ) {
					$plaetze[] = array(
						'id'     => $pl['id'],
						'geraet' => $pl['geraet'],
						'menge'  => $pl['menge'],
						'lage'   => $pl['lage'],
						'notiz'  => $pl['notiz'],
					);
				}
			}
			$faecher[] = array(
				'id'      => $f['id'],
				'kuerzel' => $f['kuerzel'],
				'name'    => $f['name'],
				'bilder'  => $bilder,
				'plaetze' => $plaetze,
			);
		}

		return array(
			'id'         => $id,
			'titel'      => get_the_title( $p ),
			'entwurf'    => 'publish' !== $p->post_status,
			'steckbrief' => array(
				'foto'   => self::bild( (int) $dok['steckbrief']['foto_id'], '', 4 / 3 ),
				'kurz'   => $dok['steckbrief']['kurz'],
				'felder' => $felder,
			),
			'ansichten'  => $ansichten,
			'faecher'    => $faecher,
			'geraete'    => $geraete,
		);
	}

	/**
	 * Bild für die Lernseite: zwei Größen (Handy/Rechner) oder eine Skizze.
	 *
	 * @return array{klein:string, klein_b:int, gross:string, gross_b:int, verhaeltnis:float, skizze:bool}|null
	 */
	public static function bild( int $foto_id, string $skizze, float $standard ): ?array {
		if ( $foto_id ) {
			$klein = wp_get_attachment_image_src( $foto_id, 'large' );
			$gross = wp_get_attachment_image_src( $foto_id, '2048x2048' ) ?: wp_get_attachment_image_src( $foto_id, 'full' );
			if ( $klein && $gross ) {
				return array(
					'klein'       => (string) $klein[0],
					'klein_b'     => (int) $klein[1],
					'gross'       => (string) $gross[0],
					'gross_b'     => (int) $gross[1],
					'verhaeltnis' => $gross[2] ? round( $gross[1] / $gross[2], 4 ) : $standard,
					'skizze'      => false,
				);
			}
		}
		if ( $skizze && in_array( $skizze, Schema::SKIZZEN, true ) ) {
			$url = FWB_URL . 'assets/skizzen/' . $skizze . '.svg';
			return array(
				'klein'       => $url,
				'klein_b'     => 1200,
				'gross'       => $url,
				'gross_b'     => 1200,
				'verhaeltnis' => $standard,
				'skizze'      => true,
			);
		}
		return null;
	}
}
