<?php
/**
 * Update aus GitHub: Antwort prüfen, Zwischenspeicher, WordPress-Filter,
 * Ordner-Absicherung. GitHub wird nie wirklich gefragt (pre_http_request).
 *
 * @package FeuerwehrBeladung
 */

use FWB\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

	private int $anfragen = 0;

	/** @var array{code:int, body:string} */
	private array $antwort = array( 'code' => 200, 'body' => '{}' );

	private array $ordner = array();

	protected function setUp(): void {
		delete_site_transient( Updater::ZWISCHENSPEICHER );
		$this->anfragen = 0;
		add_filter( 'pre_http_request', array( $this, 'falsches_github' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'falsches_github' ), 10 );
		delete_site_transient( Updater::ZWISCHENSPEICHER );
		foreach ( $this->ordner as $pfad ) {
			self::loeschen( $pfad );
		}
	}

	public function falsches_github( $vorher, $argumente, $url ) {
		if ( ! str_starts_with( $url, 'https://api.github.com/repos/' . Updater::REPO . '/' ) ) {
			return $vorher;
		}
		++$this->anfragen;
		return array(
			'headers'  => array(),
			'body'     => $this->antwort['body'],
			'response' => array( 'code' => $this->antwort['code'], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function release( string $tag, array $extra = array() ): array {
		return array_merge(
			array(
				'tag_name'     => $tag,
				'draft'        => false,
				'prerelease'   => false,
				'html_url'     => 'https://github.com/' . Updater::REPO . '/releases/tag/' . $tag,
				'published_at' => '2026-11-20T10:00:00Z',
				'body'         => "### Neu\n- Lernseite <script>alert(1)</script>\n- **Quiz**",
				'assets'       => array(
					array( 'name' => 'quelltext.zip', 'browser_download_url' => 'https://github.com/' . Updater::REPO . '/releases/download/' . $tag . '/quelltext.zip' ),
					array( 'name' => Updater::PAKET, 'browser_download_url' => 'https://github.com/' . Updater::REPO . '/releases/download/' . $tag . '/' . Updater::PAKET ),
				),
			),
			$extra
		);
	}

	private function github_liefert( array $release, int $code = 200 ): void {
		$this->antwort = array( 'code' => $code, 'body' => wp_json_encode( $release ) );
	}

	public function test_aufbereiten_nimmt_nur_passende_releases(): void {
		$r = Updater::aufbereiten( self::release( 'v1.2.3' ) );
		$this->assertSame( '1.2.3', $r['version'] );
		$this->assertStringEndsWith( '/v1.2.3/feuerwehr-beladung.zip', $r['paket'] );
		$this->assertSame( '2026-11-20 10:00:00 GMT', $r['datum'] );

		$this->assertNull( Updater::aufbereiten( self::release( 'v1.2.3', array( 'draft' => true ) ) ) );
		$this->assertNull( Updater::aufbereiten( self::release( 'v1.2.3', array( 'prerelease' => true ) ) ) );
		$this->assertNull( Updater::aufbereiten( self::release( 'neueste' ) ) );
		$this->assertNull( Updater::aufbereiten( self::release( 'v1.2.3', array( 'assets' => array() ) ) ), 'ohne feuerwehr-beladung.zip kein Update' );
		$this->assertNull(
			Updater::aufbereiten( self::release( 'v1.2.3', array( 'assets' => array( array( 'name' => Updater::PAKET, 'browser_download_url' => 'https://boese.example/feuerwehr-beladung.zip' ) ) ) ) ),
			'Pakete von fremden Adressen werden abgelehnt'
		);
		$this->assertNull( Updater::aufbereiten( array() ) );
	}

	public function test_neuere_version_wird_angeboten(): void {
		$this->github_liefert( self::release( 'v99.0.0' ) );
		$u = Updater::update_pruefen( false, array( 'Version' => FWB_VERSION, 'RequiresPHP' => '8.1' ), Updater::basisname() );
		$this->assertSame( '99.0.0', $u['version'] );
		$this->assertStringStartsWith( 'https://github.com/' . Updater::REPO . '/releases/download/', $u['package'] );
		$this->assertSame( 'feuerwehr-beladung', $u['slug'] );
		$this->assertSame( '8.1', $u['requires_php'] );
	}

	public function test_gleiche_version_ohne_paket(): void {
		$this->github_liefert( self::release( 'v' . FWB_VERSION ) );
		$u = Updater::update_pruefen( false, array( 'Version' => FWB_VERSION ), Updater::basisname() );
		$this->assertSame( FWB_VERSION, $u['version'] );
		$this->assertSame( '', $u['package'] );
	}

	public function test_andere_plugins_bleiben_unberuehrt(): void {
		$this->github_liefert( self::release( 'v99.0.0' ) );
		$this->assertFalse( Updater::update_pruefen( false, array( 'Version' => '1.0.0' ), 'anderes-plugin/anderes-plugin.php' ) );
		$this->assertSame( 0, $this->anfragen, 'für fremde Plugins wird GitHub nicht gefragt' );
	}

	public function test_github_fehler_kein_update_und_kurz_gemerkt(): void {
		$this->github_liefert( array( 'message' => 'Not Found' ), 404 );
		$this->assertFalse( Updater::update_pruefen( false, array( 'Version' => FWB_VERSION ), Updater::basisname() ) );
		$this->assertFalse( Updater::update_pruefen( false, array( 'Version' => FWB_VERSION ), Updater::basisname() ) );
		$this->assertSame( 1, $this->anfragen, 'Fehler wird gemerkt, GitHub nicht bei jedem Seitenaufruf gefragt' );
	}

	public function test_zwischenspeicher_und_frische_abfrage(): void {
		$this->github_liefert( self::release( 'v2.0.0' ) );
		Updater::neuestes_release();
		Updater::neuestes_release();
		$this->assertSame( 1, $this->anfragen );

		$this->github_liefert( self::release( 'v2.1.0' ) );
		$this->assertSame( '2.0.0', Updater::neuestes_release()['version'] );
		$this->assertSame( '2.1.0', Updater::neuestes_release( true )['version'], '„Nach Updates suchen“ fragt neu' );
		$this->assertSame( 2, $this->anfragen );
	}

	public function test_wordpress_erkennt_das_update(): void {
		// Der ganze Weg über WordPress selbst: wp_update_plugins() → Filter → Transient.
		$this->github_liefert( self::release( 'v99.0.0' ) );
		$basis = Updater::basisname();
		$filter = static function ( $plugins ) use ( $basis ) {
			$plugins[ $basis ] = array(
				'Name'      => 'Feuerwehr Beladung',
				'Version'   => FWB_VERSION,
				'UpdateURI' => 'https://github.com/' . Updater::REPO,
				'RequiresWP' => '6.5',
				'RequiresPHP' => '8.1',
			);
			return $plugins;
		};
		// wordpress.org nicht wirklich fragen.
		$ohne_wporg = static function ( $vorher, $argumente, $url ) {
			if ( str_contains( $url, 'api.wordpress.org' ) ) {
				return array( 'headers' => array(), 'body' => '{"plugins":[],"translations":[],"no_update":[]}', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			}
			return $vorher;
		};
		add_filter( 'pre_http_request', $ohne_wporg, 5, 3 );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_clean_plugins_cache( false );
		$alt = wp_cache_get( 'plugins', 'plugins' );
		wp_cache_set( 'plugins', array( '' => $filter( is_array( $alt[''] ?? null ) ? $alt[''] : get_plugins() ) ), 'plugins' );
		delete_site_transient( 'update_plugins' );

		wp_update_plugins();
		$stand = get_site_transient( 'update_plugins' );

		remove_filter( 'pre_http_request', $ohne_wporg, 5 );
		wp_clean_plugins_cache( false );
		delete_site_transient( 'update_plugins' );

		$this->assertArrayHasKey( $basis, (array) $stand->response, 'WordPress bietet das Update an' );
		$this->assertSame( '99.0.0', $stand->response[ $basis ]->new_version );
		$this->assertStringEndsWith( '/feuerwehr-beladung.zip', $stand->response[ $basis ]->package );
	}

	public function test_details_fenster_maskiert_den_text(): void {
		$this->github_liefert( self::release( 'v3.0.0' ) );
		$info = Updater::details( false, 'plugin_information', (object) array( 'slug' => 'feuerwehr-beladung' ) );
		$this->assertSame( '3.0.0', $info->version );
		$this->assertStringContainsString( '<h4>Neu</h4>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<li>Quiz</li>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );

		$this->assertFalse( Updater::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
	}

	public function test_ordner_wird_umbenannt(): void {
		$this->dateisystem();
		$entpackt = $this->neuer_ordner();
		mkdir( $entpackt . '/feuerwehr_beladung-main' );
		file_put_contents( $entpackt . '/feuerwehr_beladung-main/feuerwehr-beladung.php', '<?php' );

		$ergebnis = Updater::ordner_richten( $entpackt . '/feuerwehr_beladung-main/', $entpackt, null, array( 'plugin' => Updater::basisname() ) );
		$this->assertSame( trailingslashit( $entpackt ) . 'feuerwehr-beladung/', $ergebnis );
		$this->assertFileExists( $entpackt . '/feuerwehr-beladung/feuerwehr-beladung.php' );

		// Richtiger Name: nichts tun.
		$this->assertSame( $ergebnis, Updater::ordner_richten( $ergebnis, $entpackt, null, array( 'plugin' => Updater::basisname() ) ) );
	}

	public function test_falsches_paket_wird_abgelehnt(): void {
		$this->dateisystem();
		$entpackt = $this->neuer_ordner();
		mkdir( $entpackt . '/irgendwas' );
		$ergebnis = Updater::ordner_richten( $entpackt . '/irgendwas/', $entpackt, null, array( 'plugin' => Updater::basisname() ) );
		$this->assertWPError( $ergebnis );

		// Andere Plugins: unverändert durchreichen.
		$this->assertSame( '/x/', Updater::ordner_richten( '/x/', '/', null, array( 'plugin' => 'akismet/akismet.php' ) ) );
	}

	public function test_link_nur_fuer_administratoren(): void {
		$redakteur = wp_insert_user( array( 'user_login' => 'upd_red_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
		$admin     = wp_insert_user( array( 'user_login' => 'upd_adm_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );

		wp_set_current_user( $redakteur );
		$this->assertSame( array(), Updater::zeilen_links( array(), Updater::basisname() ) );

		wp_set_current_user( $admin );
		$links = Updater::zeilen_links( array(), Updater::basisname() );
		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'Nach Updates suchen', $links[0] );
		$this->assertStringContainsString( '_wpnonce=', $links[0] );
		$this->assertSame( array(), Updater::zeilen_links( array(), 'akismet/akismet.php' ) );

		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $redakteur );
		wp_delete_user( $admin );
	}

	private function assertWPError( $wert ): void {
		$this->assertInstanceOf( WP_Error::class, $wert );
	}

	private function dateisystem(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		add_filter( 'filesystem_method', static fn() => 'direct' );
		WP_Filesystem();
	}

	private function neuer_ordner(): string {
		$pfad = sys_get_temp_dir() . '/fwb-test-' . wp_generate_password( 8, false );
		mkdir( $pfad );
		$this->ordner[] = $pfad;
		return $pfad;
	}

	private static function loeschen( string $pfad ): void {
		if ( is_dir( $pfad ) ) {
			foreach ( array_diff( scandir( $pfad ), array( '.', '..' ) ) as $eintrag ) {
				self::loeschen( $pfad . '/' . $eintrag );
			}
			rmdir( $pfad );
		} elseif ( file_exists( $pfad ) ) {
			unlink( $pfad );
		}
	}
}
