<?php
/**
 * Lernseite und Fotoschutz: Wer sieht was?
 *
 * @package FeuerwehrBeladung
 */

use FWB\Beladung;
use FWB\Fahrzeug_Typ;
use FWB\Fotoschutz;
use FWB\Geraete_Katalog;
use FWB\Lern_Daten;
use FWB\Lernseite;
use PHPUnit\Framework\TestCase;

final class LernseiteTest extends TestCase {

	/** @var int[] */
	private array $posts = array();

	/** @var int[] */
	private array $nutzer = array();

	protected function setUp(): void {
		Geraete_Katalog::zwischenspeicher_leeren();
		wp_set_current_user( $this->nutzer( 'administrator' ) );
	}

	protected function tearDown(): void {
		wp_set_current_user( $this->nutzer( 'administrator' ) );
		foreach ( array_reverse( $this->posts ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				wp_delete_attachment( $id, true );
			} else {
				wp_delete_post( $id, true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->nutzer as $id ) {
			wp_delete_user( $id );
		}
		wp_set_current_user( 0 );
	}

	private function nutzer( string $rolle ): int {
		if ( isset( $this->nutzer[ $rolle ] ) ) {
			return $this->nutzer[ $rolle ];
		}
		$id                      = wp_insert_user( array( 'user_login' => 'ls_' . $rolle . '_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => $rolle ) );
		$this->nutzer[ $rolle ] = $id;
		return $id;
	}

	private function bild( int $eltern = 0 ): int {
		$uploads = wp_upload_dir();
		$datei   = $uploads['path'] . '/fwb-test-' . wp_generate_password( 6, false ) . '.jpg';
		$gd      = imagecreatetruecolor( 1600, 1200 );
		imagefill( $gd, 0, 0, imagecolorallocate( $gd, 200, 30, 40 ) );
		imagejpeg( $gd, $datei, 70 );
		imagedestroy( $gd );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Testbild', 'post_status' => 'inherit' ), $datei, $eltern );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $datei ) );
		$this->posts[] = $id;
		return $id;
	}

	private function fahrzeug( string $status, array $dok = array() ): int {
		$id            = wp_insert_post( array( 'post_type' => Fahrzeug_Typ::TYP, 'post_status' => $status, 'post_title' => 'Test ' . $status ) );
		$this->posts[] = $id;
		Beladung::speichern( $id, $dok );
		return $id;
	}

	/* ------------------------------ Schnittstelle ------------------------------ */

	public function test_ohne_anmeldung_gibt_es_nichts(): void {
		$id = $this->fahrzeug( 'publish' );
		wp_set_current_user( 0 );
		foreach ( array( '/fwb/v1/fahrzeuge', '/fwb/v1/fahrzeuge/' . $id ) as $route ) {
			$antwort = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 401, $antwort->get_status(), $route );
		}
		$this->assertStringContainsString( 'Anmelden', Lernseite::ausgeben() );
		$this->assertStringNotContainsString( 'data-konfig', Lernseite::ausgeben(), 'kein Nonce, keine Adresse für Unangemeldete' );
	}

	public function test_mitglied_sieht_nur_veroeffentlichte(): void {
		$sichtbar = $this->fahrzeug( 'publish' );
		$entwurf  = $this->fahrzeug( 'draft' );
		wp_set_current_user( $this->nutzer( 'subscriber' ) );

		$ids = wp_list_pluck( Lern_Daten::fahrzeuge(), 'id' );
		$this->assertContains( $sichtbar, $ids );
		$this->assertNotContains( $entwurf, $ids );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/fwb/v1/fahrzeuge/' . $entwurf ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/fwb/v1/fahrzeuge/' . $sichtbar ) )->get_status() );
		$this->assertStringContainsString( 'data-konfig', Lernseite::ausgeben() );
	}

	public function test_redakteur_sieht_auch_entwuerfe(): void {
		$entwurf = $this->fahrzeug( 'draft' );
		wp_set_current_user( $this->nutzer( 'editor' ) );
		$eintrag = wp_list_filter( Lern_Daten::fahrzeuge(), array( 'id' => $entwurf ) );
		$this->assertCount( 1, $eintrag );
		$this->assertTrue( array_values( $eintrag )[0]['entwurf'] );
	}

	public function test_fahrzeugdaten_enthalten_katalogtexte_und_nur_noetiges(): void {
		$uid = Geraete_Katalog::anlegen( 'Lerntest-Leine ' . wp_generate_password( 4, false ), 'Zum Sichern.', 'Nicht knoten.', 'Seil' );
		$this->posts[] = Geraete_Katalog::alle()[ $uid ]['id'];
		$id = $this->fahrzeug(
			'publish',
			array(
				'faecher' => array(
					array(
						'id'      => 'f_lern01',
						'kuerzel' => 'G1',
						'plaetze' => array( array( 'id' => 'p_lern01', 'geraet' => $uid, 'menge' => '2', 'lage' => 'oben' ) ),
						'bilder'  => array( array( 'id' => 'b_lern01', 'titel' => 'auf', 'skizze' => 'fach', 'markierungen' => array( array( 'id' => 'm_lern01', 'platz_id' => 'p_lern01', 'x' => 1, 'y' => 2, 'b' => 3, 'h' => 4, 'notiz' => 'links' ) ) ) ),
					),
				),
			)
		);
		wp_set_current_user( $this->nutzer( 'subscriber' ) );
		$d = Lern_Daten::fahrzeug( $id );
		$this->assertSame( 'Zum Sichern.', $d['geraete'][ $uid ]['beschreibung'] );
		$this->assertSame( 'Nicht knoten.', $d['geraete'][ $uid ]['hinweise'] );
		$this->assertSame( 'links', $d['faecher'][0]['bilder'][0]['markierungen'][0]['notiz'] );
		$this->assertTrue( $d['faecher'][0]['bilder'][0]['bild']['skizze'] );
		$this->assertArrayNotHasKey( 'id', $d['faecher'][0]['bilder'][0]['markierungen'][0], 'Markierungs-IDs braucht die Lernseite nicht' );
		$this->assertCount( 1, $d['geraete'], 'nur Geräte dieses Fahrzeugs' );
	}

	/* ------------------------------ Fotoschutz ------------------------------ */

	public function test_foto_wird_geschuetzt_und_nur_angemeldet_ausgeliefert(): void {
		$fahrzeug = $this->fahrzeug( 'publish' );
		$foto     = $this->bild( $fahrzeug );
		$alt      = get_attached_file( $foto );
		$this->assertTrue( Fotoschutz::automatisch_erlaubt( $foto ), 'zum Fahrzeug hochgeladen' );

		$this->assertTrue( Fotoschutz::schuetzen( $foto ) );
		$this->assertFileDoesNotExist( $alt, 'Original ist aus dem öffentlichen Ordner verschwunden' );
		$neu = get_attached_file( $foto );
		$this->assertFileExists( $neu );
		$this->assertStringContainsString( '/' . Fotoschutz::ORDNER . '/', $neu );
		$this->assertMatchesRegularExpression( '#/' . Fotoschutz::ORDNER . '/[a-z0-9]{32}/#', $neu, 'zufälliger Unterordner' );
		$this->assertFileExists( Fotoschutz::ordner()['pfad'] . '/.htaccess' );

		// Alle Adressen gehen über das Plugin, auch die der anderen Größen.
		$this->assertStringContainsString( '?fwb_bild=' . $foto . '&d=/', wp_get_attachment_url( $foto ) );
		$gross = wp_get_attachment_image_src( $foto, 'large' );
		$this->assertStringContainsString( '?fwb_bild=' . $foto . '&d=/', $gross[0] );
		$this->assertStringContainsString( '-1024x', $gross[0] );

		$datei = wp_basename( $neu );
		wp_set_current_user( 0 );
		$this->assertSame( 403, Fotoschutz::datei_fuer_anfrage( $foto, $datei ), 'ohne Anmeldung' );
		wp_set_current_user( $this->nutzer( 'subscriber' ) );
		$this->assertSame( realpath( $neu ), Fotoschutz::datei_fuer_anfrage( $foto, $datei ) );
		$this->assertSame( 404, Fotoschutz::datei_fuer_anfrage( $foto, 'wp-config.php' ), 'nur Dateien dieses Bildes' );
		$this->assertSame( 404, Fotoschutz::datei_fuer_anfrage( $foto, '../../../wp-config.php' ) );

		// Medien-Schnittstelle verrät das Foto nicht.
		wp_set_current_user( 0 );
		$antwort = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media/' . $foto ) );
		$this->assertContains( $antwort->get_status(), array( 401, 404 ), 'kein Zugriff – und kein Serverfehler' );
		$this->assertArrayNotHasKey( 'source_url', (array) $antwort->get_data() );
		$liste = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media' ) );
		$this->assertNotContains( $foto, wp_list_pluck( (array) $liste->get_data(), 'id' ) );

		// Löschen räumt auch den leeren Zufallsordner weg.
		wp_set_current_user( $this->nutzer( 'administrator' ) );
		$ordner = dirname( $neu );
		wp_delete_attachment( $foto, true );
		$this->assertDirectoryDoesNotExist( $ordner );
	}

	public function test_fremde_fotos_werden_nicht_automatisch_verschoben(): void {
		$foto = $this->bild( 0 );
		$alt  = get_attached_file( $foto );
		$this->assertFalse( Fotoschutz::automatisch_erlaubt( $foto ) );
		$ergebnis = Fotoschutz::dokument_schuetzen( array( 'steckbrief' => array( 'foto_id' => $foto ) ) );
		$this->assertSame( array( $foto ), $ergebnis['offen'] );
		$this->assertFileExists( $alt, 'bleibt, wo es ist' );
		$this->assertFalse( Fotoschutz::ist_geschuetzt( $foto ) );
	}

	public function test_schuetzen_per_rest_nur_fuer_bearbeiter(): void {
		$foto = $this->bild( 0 );
		wp_set_current_user( $this->nutzer( 'subscriber' ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/fwb/v1/fotos/' . $foto . '/schuetzen' ) )->get_status() );
		wp_set_current_user( $this->nutzer( 'editor' ) );
		$antwort = rest_do_request( new WP_REST_Request( 'POST', '/fwb/v1/fotos/' . $foto . '/schuetzen' ) );
		$this->assertSame( 200, $antwort->get_status() );
		$this->assertTrue( Fotoschutz::ist_geschuetzt( $foto ) );
	}
}
