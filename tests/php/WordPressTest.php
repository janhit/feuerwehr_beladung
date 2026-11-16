<?php
/**
 * Zusammenspiel mit WordPress: Rechte, Gerätekatalog, Beispielfahrzeug,
 * Versionen, REST-Schnittstelle, Protokoll.
 *
 * @package FeuerwehrBeladung
 */

use FWB\Beispiel;
use FWB\Beladung;
use FWB\Fahrzeug_Typ;
use FWB\Geraete_Katalog;
use FWB\Protokoll;
use PHPUnit\Framework\TestCase;

final class WordPressTest extends TestCase {

	/** @var int[] */
	private array $aufraeumen = array();

	/** @var int[] */
	private array $nutzer = array();

	protected function setUp(): void {
		Geraete_Katalog::zwischenspeicher_leeren();
		wp_set_current_user( $this->nutzer( 'administrator' ) );
	}

	protected function tearDown(): void {
		// Erst die Fahrzeuge, dann sind die Geräte frei und dürfen gelöscht werden.
		foreach ( $this->aufraeumen as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( get_posts( array( 'post_type' => Geraete_Katalog::TYP, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->nutzer as $id ) {
			wp_delete_user( $id );
		}
		Geraete_Katalog::zwischenspeicher_leeren();
		wp_set_current_user( 0 );
	}

	private function nutzer( string $rolle ): int {
		$id             = wp_insert_user(
			array(
				'user_login' => 'fwbtest_' . $rolle . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $rolle,
			)
		);
		$this->nutzer[] = $id;
		return $id;
	}

	public function test_rechte_der_rollen(): void {
		$this->assertTrue( user_can( $this->nutzer( 'administrator' ), 'fwb_verwalten' ) );
		$this->assertTrue( user_can( $this->nutzer( 'editor' ), 'fwb_bearbeiten' ) );
		$this->assertFalse( user_can( $this->nutzer( 'editor' ), 'fwb_verwalten' ) );
		$this->assertFalse( user_can( $this->nutzer( 'subscriber' ), 'fwb_bearbeiten' ) );
		$this->assertFalse( user_can( $this->nutzer( 'author' ), 'fwb_bearbeiten' ) );
	}

	public function test_beitragstyp_ist_nicht_oeffentlich(): void {
		$typ = get_post_type_object( Fahrzeug_Typ::TYP );
		$this->assertFalse( $typ->public );
		$this->assertFalse( $typ->publicly_queryable );
		$this->assertFalse( $typ->show_in_rest );
	}

	public function test_beispielfahrzeug(): void {
		$id                 = Beispiel::anlegen();
		$this->aufraeumen[] = $id;
		$this->assertSame( 'draft', get_post_status( $id ) );

		$dok     = Beladung::lesen( $id );
		$kuerzel = array_column( $dok['faecher'], 'kuerzel' );
		$this->assertSame( array( 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'GR', 'D1', 'D2' ), $kuerzel );
		$this->assertSame( array( 'Fahrerseite', 'Beifahrerseite', 'Heck', 'Dach' ), array_column( $dok['ansichten'], 'name' ) );

		// G1: Schwenkwand mit zwei Bildern, Schalter hin und zurück.
		$g1 = $dok['faecher'][0];
		$this->assertCount( 2, $g1['bilder'] );
		$this->assertSame( 'schwenk', $g1['bilder'][0]['schalter'][0]['art'] );
		$this->assertSame( $g1['bilder'][1]['id'], $g1['bilder'][0]['schalter'][0]['ziel_bild_id'] );
		$this->assertSame( $g1['bilder'][0]['id'], $g1['bilder'][1]['schalter'][0]['ziel_bild_id'] );
		$this->assertCount( 7, $g1['plaetze'] );
		// G2: Auszug.
		$this->assertSame( 'auszug', $dok['faecher'][1]['bilder'][0]['schalter'][0]['art'] );
		// Jede Markierung zeigt auf einen Platz.
		foreach ( $dok['faecher'] as $fach ) {
			$plaetze = array_column( $fach['plaetze'], 'id' );
			foreach ( $fach['bilder'] as $bild ) {
				foreach ( $bild['markierungen'] as $m ) {
					$this->assertContains( $m['platz_id'], $plaetze );
				}
			}
		}
		// Die Feuerwehrleine liegt in G1 und G3.
		$leine = Geraete_Katalog::anlegen( 'Feuerwehrleine' );
		$orte  = array_column( Geraete_Katalog::verwendungen( $leine ), 'fach' );
		$this->assertSame( array( 'G1', 'G3' ), $orte );

		// Zweites Beispiel nutzt dieselben Katalog-Geräte.
		$anzahl             = count( Geraete_Katalog::alle() );
		$this->aufraeumen[] = Beispiel::anlegen();
		Geraete_Katalog::zwischenspeicher_leeren();
		$this->assertSame( $anzahl, count( Geraete_Katalog::alle() ) );
	}

	public function test_versionen_werden_aufgehoben(): void {
		$id                 = wp_insert_post( array( 'post_type' => Fahrzeug_Typ::TYP, 'post_title' => 'Test', 'post_status' => 'draft' ) );
		$this->aufraeumen[] = $id;
		$uid                = Geraete_Katalog::anlegen( 'Testgerät' );

		$doc = array( 'faecher' => array( array( 'kuerzel' => 'G1', 'plaetze' => array( array( 'geraet' => $uid ) ) ) ) );
		$this->assertTrue( Beladung::speichern( $id, $doc )['geaendert'] );
		$this->assertSame( array(), Beladung::versionen( $id ) );

		$gespeichert = Beladung::lesen( $id );
		$this->assertFalse( Beladung::speichern( $id, $gespeichert )['geaendert'], 'Gleicher Stand erzeugt keine Version.' );

		$gespeichert['faecher'][0]['kuerzel'] = 'G9';
		$this->assertTrue( Beladung::speichern( $id, $gespeichert )['geaendert'] );
		$versionen = Beladung::versionen( $id );
		$this->assertCount( 1, $versionen );
		$this->assertSame( 'G1', json_decode( $versionen[0]['json'], true )['faecher'][0]['kuerzel'] );

		// Sonderzeichen und Backslashes überstehen das Speichern.
		$gespeichert['faecher'][0]['name'] = 'Fach „links“ \\ Ä';
		Beladung::speichern( $id, $gespeichert );
		$this->assertSame( 'Fach „links“ \\ Ä', Beladung::lesen( $id )['faecher'][0]['name'] );
	}

	public function test_geraet_in_benutzung_laesst_sich_nicht_loeschen(): void {
		$id                 = wp_insert_post( array( 'post_type' => Fahrzeug_Typ::TYP, 'post_title' => 'Test', 'post_status' => 'draft' ) );
		$this->aufraeumen[] = $id;
		$benutzt            = Geraete_Katalog::anlegen( 'Benutzt' );
		$frei               = Geraete_Katalog::anlegen( 'Frei' );
		Beladung::speichern( $id, array( 'faecher' => array( array( 'kuerzel' => 'G1', 'plaetze' => array( array( 'geraet' => $benutzt ) ) ) ) ) );

		$alle = Geraete_Katalog::alle();
		$this->assertFalse( wp_trash_post( $alle[ $benutzt ]['id'] ) );
		$this->assertSame( 'publish', get_post_status( $alle[ $benutzt ]['id'] ) );
		$this->assertNotFalse( wp_trash_post( $alle[ $frei ]['id'] ) );
	}

	public function test_gleicher_name_legt_kein_zweites_geraet_an(): void {
		$a = Geraete_Katalog::anlegen( 'Kübelspritze' );
		$b = Geraete_Katalog::anlegen( 'kübelspritze' );
		$this->assertSame( $a, $b );
	}

	public function test_rest_katalog_nur_fuer_bearbeiter(): void {
		Geraete_Katalog::anlegen( 'Schaufel' );

		wp_set_current_user( 0 );
		$antwort = rest_do_request( new WP_REST_Request( 'GET', '/fwb/v1/geraete' ) );
		$this->assertSame( 401, $antwort->get_status() );

		wp_set_current_user( $this->nutzer( 'subscriber' ) );
		$antwort = rest_do_request( new WP_REST_Request( 'GET', '/fwb/v1/geraete' ) );
		$this->assertSame( 403, $antwort->get_status() );

		wp_set_current_user( $this->nutzer( 'editor' ) );
		$anfrage = new WP_REST_Request( 'GET', '/fwb/v1/geraete' );
		$anfrage->set_param( 'suche', 'schau' );
		$antwort = rest_do_request( $anfrage );
		$this->assertSame( 200, $antwort->get_status() );
		$this->assertSame( 'Schaufel', $antwort->get_data()[0]['name'] );

		$anlegen = new WP_REST_Request( 'POST', '/fwb/v1/geraete' );
		$anlegen->set_param( 'name', 'Brechstange' );
		$antwort = rest_do_request( $anlegen );
		$this->assertSame( 201, $antwort->get_status() );
		$this->assertMatchesRegularExpression( '/^g_[a-z0-9]+$/', $antwort->get_data()['geraet']['uid'] );
	}

	public function test_protokoll_schreibt_eintrag(): void {
		Protokoll::eintragen( 'test', 1, 'geprüft', 'Testeintrag' );
		$letzter = Protokoll::letzte( 1 )[0];
		$this->assertSame( 'Testeintrag', $letzter->text );
		$this->assertSame( get_current_user_id(), (int) $letzter->user_id );
	}
}
