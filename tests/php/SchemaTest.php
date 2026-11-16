<?php
/**
 * Bereinigung des Beladungs-Dokuments, Feld für Feld.
 *
 * @package FeuerwehrBeladung
 */

use FWB\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	private function bereinigen( array $roh, array $bilder = array( 5 ), array $geraete = array( 'g_leine1', 'g_lampe1' ) ): array {
		return Schema::bereinigen(
			$roh,
			static fn( int $id ): bool => in_array( $id, $bilder, true ),
			static fn( string $uid ): bool => in_array( $uid, $geraete, true )
		);
	}

	private function fach( array $zusatz = array() ): array {
		return array_merge(
			array(
				'id'      => 'f_fach01',
				'kuerzel' => 'G1',
				'name'    => 'Geräteraum 1',
				'plaetze' => array(
					array( 'id' => 'p_platz1', 'geraet' => 'g_leine1', 'menge' => '2', 'lage' => 'links' ),
				),
				'bilder'  => array(
					array(
						'id'           => 'b_bild01',
						'titel'        => 'zu',
						'foto_id'      => 5,
						'markierungen' => array( array( 'id' => 'm_mark01', 'platz_id' => 'p_platz1', 'x' => 10, 'y' => 10, 'b' => 20, 'h' => 20 ) ),
						'schalter'     => array( array( 'id' => 's_schal1', 'art' => 'schwenk', 'ziel_bild_id' => 'b_bild02', 'x' => 50, 'y' => 50 ) ),
					),
					array( 'id' => 'b_bild02', 'titel' => 'auf' ),
				),
			),
			$zusatz
		);
	}

	public function test_leeres_dokument_hat_alle_teile(): void {
		[ $dok, $hinweise ] = $this->bereinigen( array() );
		$this->assertSame( Schema::leer(), $dok );
		$this->assertSame( array(), $hinweise );
	}

	public function test_gueltiges_fach_bleibt_erhalten(): void {
		[ $dok, $hinweise ] = $this->bereinigen( array( 'faecher' => array( $this->fach() ) ) );
		$this->assertSame( array(), $hinweise );
		$fach = $dok['faecher'][0];
		$this->assertSame( 'f_fach01', $fach['id'] );
		$this->assertSame( 'g_leine1', $fach['plaetze'][0]['geraet'] );
		$this->assertCount( 2, $fach['bilder'] );
		$this->assertSame( 'b_bild02', $fach['bilder'][0]['schalter'][0]['ziel_bild_id'] );
		$this->assertSame( 5, $fach['bilder'][0]['foto_id'] );
	}

	public function test_unbekannte_felder_werden_verworfen_und_texte_bereinigt(): void {
		$fach              = $this->fach( array( 'boese' => 'x', 'name' => '<script>alert(1)</script>Fach' ) );
		$fach['plaetze'][0]['lage'] = "<b>oben</b>\nlinks";
		[ $dok ] = $this->bereinigen( array( 'faecher' => array( $fach ), 'extra' => array( 1 ) ) );
		$this->assertArrayNotHasKey( 'boese', $dok['faecher'][0] );
		$this->assertArrayNotHasKey( 'extra', $dok );
		$this->assertStringNotContainsString( '<', $dok['faecher'][0]['name'] );
		$this->assertStringNotContainsString( '<', $dok['faecher'][0]['plaetze'][0]['lage'] );
	}

	public function test_positionen_bleiben_im_bild(): void {
		$fach = $this->fach();
		$fach['bilder'][0]['markierungen'][0] = array( 'id' => 'm_mark01', 'platz_id' => 'p_platz1', 'x' => 150, 'y' => 'abc', 'b' => 30, 'h' => -4 );
		[ $dok ] = $this->bereinigen( array( 'faecher' => array( $fach ) ) );
		$m = $dok['faecher'][0]['bilder'][0]['markierungen'][0];
		$this->assertSame( array( 70.0, 0.0, 30.0, 1.0 ), array( $m['x'], $m['y'], $m['b'], $m['h'] ) );
	}

	public function test_ungueltige_und_doppelte_ids_werden_ersetzt(): void {
		$fach1 = $this->fach( array( 'id' => 'f_fach01' ) );
		$fach2 = $this->fach( array( 'id' => 'f_fach01', 'kuerzel' => 'G2' ) );
		$fach3 = $this->fach( array( 'id' => '"><img>', 'kuerzel' => 'G3' ) );
		[ $dok ] = $this->bereinigen( array( 'faecher' => array( $fach1, $fach2, $fach3 ) ) );
		$ids = array_column( $dok['faecher'], 'id' );
		$this->assertSame( 'f_fach01', $ids[0] );
		$this->assertCount( 3, array_unique( $ids ) );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^f_[a-z0-9]{4,20}$/', $id );
		}
	}

	public function test_kaputte_verweise_werden_entfernt(): void {
		$fach = $this->fach();
		$fach['bilder'][0]['markierungen'][] = array( 'id' => 'm_mark02', 'platz_id' => 'p_gibtsnicht', 'x' => 1, 'y' => 1, 'b' => 5, 'h' => 5 );
		$fach['bilder'][0]['schalter'][]     = array( 'id' => 's_schal2', 'art' => 'auszug', 'ziel_bild_id' => 'b_bild01', 'x' => 1, 'y' => 1 );
		$fach['plaetze'][]                   = array( 'id' => 'p_platz2', 'geraet' => 'g_unbekannt' );
		$roh = array(
			'faecher'   => array( $fach ),
			'ansichten' => array(
				array(
					'id'       => 'a_seite1',
					'name'     => 'Fahrerseite',
					'bereiche' => array(
						array( 'id' => 'r_ber001', 'fach_id' => 'f_fach01', 'x' => 1, 'y' => 1, 'b' => 10, 'h' => 10 ),
						array( 'id' => 'r_ber002', 'fach_id' => 'f_fach01', 'x' => 1, 'y' => 1, 'b' => 10, 'h' => 10 ),
						array( 'id' => 'r_ber003', 'fach_id' => 'f_weg', 'x' => 1, 'y' => 1, 'b' => 10, 'h' => 10 ),
					),
				),
			),
		);
		[ $dok, $hinweise ] = $this->bereinigen( $roh );
		$bild = $dok['faecher'][0]['bilder'][0];
		$this->assertCount( 1, $bild['markierungen'] );
		$this->assertCount( 1, $bild['schalter'], 'Schalter auf das eigene Bild wird entfernt.' );
		$this->assertCount( 1, $dok['faecher'][0]['plaetze'], 'Gerät ohne Katalogeintrag wird entfernt.' );
		$this->assertCount( 1, $dok['ansichten'][0]['bereiche'], 'Doppeltes und unbekanntes Fach werden entfernt.' );
		$this->assertNotEmpty( $hinweise );
	}

	public function test_gleiches_geraet_zweimal_im_fach_wird_einmal_gespeichert(): void {
		$fach              = $this->fach();
		$fach['plaetze'][] = array( 'id' => 'p_platz2', 'geraet' => 'g_leine1' );
		[ $dok ] = $this->bereinigen( array( 'faecher' => array( $fach ) ) );
		$this->assertCount( 1, $dok['faecher'][0]['plaetze'] );
	}

	public function test_gleiches_geraet_in_zwei_faechern_ist_erlaubt(): void {
		$fach2 = $this->fach( array( 'id' => 'f_fach02', 'kuerzel' => 'G3' ) );
		$fach2['plaetze'][0]['id'] = 'p_platz9';
		$fach2['bilder']           = array( array( 'id' => 'b_bild09', 'markierungen' => array( array( 'platz_id' => 'p_platz9', 'x' => 1, 'y' => 1, 'b' => 5, 'h' => 5 ) ) ) );
		[ $dok ] = $this->bereinigen( array( 'faecher' => array( $this->fach(), $fach2 ) ) );
		$this->assertSame( array( 'g_leine1' ), Schema::geraete_uids( $dok ) );
		$this->assertCount( 2, $dok['faecher'] );
		$this->assertCount( 1, $dok['faecher'][1]['bilder'][0]['markierungen'] );
	}

	public function test_fach_ohne_bild_bekommt_eines_und_ohne_kuerzel_einen_namen(): void {
		[ $dok ] = $this->bereinigen( array( 'faecher' => array( array( 'kuerzel' => '' ) ) ) );
		$this->assertCount( 1, $dok['faecher'][0]['bilder'] );
		$this->assertSame( 'G1', $dok['faecher'][0]['kuerzel'] );
	}

	public function test_hoechstens_drei_eckdaten(): void {
		$felder = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$felder[] = array( 'label' => 'Feld ' . $i, 'wert' => 'x', 'eckdatum' => true );
		}
		$felder[] = array( 'label' => '', 'wert' => 'ohne Bezeichnung' );
		[ $dok ] = $this->bereinigen( array( 'steckbrief' => array( 'felder' => $felder ) ) );
		$this->assertCount( 5, $dok['steckbrief']['felder'] );
		$this->assertSame( 3, count( array_filter( array_column( $dok['steckbrief']['felder'], 'eckdatum' ) ) ) );
	}

	public function test_fremde_foto_ids_und_skizzen_werden_verworfen(): void {
		[ $dok ] = $this->bereinigen(
			array(
				'steckbrief' => array( 'foto_id' => 99 ),
				'ansichten'  => array( array( 'name' => 'Heck', 'foto_id' => '5', 'skizze' => '../../wp-config' ) ),
			)
		);
		$this->assertSame( 0, $dok['steckbrief']['foto_id'] );
		$this->assertSame( 5, $dok['ansichten'][0]['foto_id'] );
		$this->assertSame( '', $dok['ansichten'][0]['skizze'] );
	}

	public function test_zu_viele_eintraege_werden_gekappt(): void {
		$ansichten = array_fill( 0, 50, array( 'name' => 'x' ) );
		[ $dok, $hinweise ] = $this->bereinigen( array( 'ansichten' => $ansichten ) );
		$this->assertCount( 20, $dok['ansichten'] );
		$this->assertNotEmpty( $hinweise );
	}
}
