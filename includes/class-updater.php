<?php
/**
 * Update aus den GitHub-Releases.
 *
 * WordPress fragt bei Plugins mit „Update URI: https://github.com/…“ nicht
 * wordpress.org, sondern ruft den Filter update_plugins_github.com auf. Hier
 * holen wir das neueste Release von GitHub. Ist es neuer als die installierte
 * Version und hat es die Datei feuerwehr-beladung.zip, zeigt WordPress unter
 * „Plugins“ den gewohnten Knopf „Jetzt aktualisieren“.
 *
 * Ein Update ersetzt nur den Plugin-Ordner. Fahrzeuge, Fotos und Lernstände
 * liegen in Datenbank und Mediathek und bleiben unverändert.
 *
 * Datenschutz: Die Abfrage geht vom Server an api.github.com (nicht vom Browser
 * der Mitglieder) und enthält keine Nutzerdaten.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPO         = 'janhit/feuerwehr_beladung';
	public const SLUG         = 'feuerwehr-beladung';
	public const PAKET        = 'feuerwehr-beladung.zip';
	public const ZWISCHENSPEICHER = 'fwb_update_release';

	/** So lange merken wir uns die Antwort von GitHub. */
	private const DAUER_OK     = 6 * HOUR_IN_SECONDS;
	private const DAUER_FEHLER = 30 * MINUTE_IN_SECONDS;

	public static function haken(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'update_pruefen' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'ordner_richten' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( self::class, 'zeilen_links' ), 10, 2 );
		add_action( 'admin_post_fwb_update_suchen', array( self::class, 'jetzt_suchen' ) );
		add_action( 'admin_notices', array( self::class, 'hinweis' ) );
	}

	public static function basisname(): string {
		return plugin_basename( FWB_DATEI );
	}

	public static function api_url(): string {
		return (string) apply_filters( 'fwb_update_api_url', 'https://api.github.com/repos/' . self::REPO . '/releases/latest' );
	}

	/**
	 * Nur Pakete von genau dieser Adresse werden angenommen.
	 */
	public static function paket_praefix(): string {
		return (string) apply_filters( 'fwb_update_paket_praefix', 'https://github.com/' . self::REPO . '/releases/download/' );
	}

	/**
	 * Neuestes Release, aufbereitet. null, wenn GitHub nicht erreichbar ist
	 * oder es kein passendes Release gibt.
	 *
	 * @return array{version:string, paket:string, seite:string, text:string, datum:string}|null
	 */
	public static function neuestes_release( bool $frisch = false ): ?array {
		if ( ! $frisch ) {
			$gemerkt = get_site_transient( self::ZWISCHENSPEICHER );
			if ( is_array( $gemerkt ) ) {
				return $gemerkt['release'] ?? null;
			}
		}

		$release = self::abfragen();
		set_site_transient(
			self::ZWISCHENSPEICHER,
			array( 'release' => $release, 'zeit' => time() ),
			null === $release ? self::DAUER_FEHLER : self::DAUER_OK
		);
		return $release;
	}

	private static function abfragen(): ?array {
		$antwort = wp_remote_get(
			self::api_url(),
			array(
				'timeout'    => 10,
				'user-agent' => 'Feuerwehr-Beladung-Updater',
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $antwort ) || 200 !== (int) wp_remote_retrieve_response_code( $antwort ) ) {
			return null;
		}
		$daten = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );
		return self::aufbereiten( is_array( $daten ) ? $daten : array() );
	}

	/**
	 * Prüft eine Release-Antwort von GitHub und nimmt nur, was wir brauchen.
	 */
	public static function aufbereiten( array $daten ): ?array {
		if ( ! empty( $daten['draft'] ) || ! empty( $daten['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $daten['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}
		$paket = '';
		foreach ( (array) ( $daten['assets'] ?? array() ) as $datei ) {
			if ( self::PAKET === ( $datei['name'] ?? '' ) ) {
				$paket = (string) ( $datei['browser_download_url'] ?? '' );
				break;
			}
		}
		if ( '' === $paket || ! str_starts_with( $paket, self::paket_praefix() ) ) {
			return null;
		}
		$seite = (string) ( $daten['html_url'] ?? '' );
		if ( ! str_starts_with( $seite, 'https://github.com/' ) ) {
			$seite = 'https://github.com/' . self::REPO . '/releases';
		}
		return array(
			'version' => $version,
			'paket'   => $paket,
			'seite'   => $seite,
			'text'    => mb_substr( (string) ( $daten['body'] ?? '' ), 0, 20000 ),
			// WordPress zeigt daraus „vor 3 Tagen“ an, braucht also die Uhrzeit (UTC).
			'datum'   => self::zeitpunkt( (string) ( $daten['published_at'] ?? '' ) ),
		);
	}

	private static function zeitpunkt( string $iso ): string {
		$zeit = strtotime( $iso );
		return $zeit ? gmdate( 'Y-m-d H:i:s', $zeit ) . ' GMT' : '';
	}

	/**
	 * Filter update_plugins_github.com: Antwort für WordPress' Update-Prüfung.
	 *
	 * @param array|false $update      Bisheriges Ergebnis.
	 * @param array       $plugin_daten Kopfzeilen des Plugins.
	 * @param string      $datei        z. B. feuerwehr-beladung/feuerwehr-beladung.php
	 */
	public static function update_pruefen( $update, $plugin_daten, $datei ) {
		if ( self::basisname() !== $datei ) {
			return $update;
		}
		$release = self::neuestes_release();
		if ( null === $release ) {
			return $update;
		}
		$installiert = (string) ( $plugin_daten['Version'] ?? FWB_VERSION );
		return array(
			'id'           => 'https://github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $datei,
			// Gleich oder älter: WordPress führt das Plugin dann als „aktuell“.
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['seite'],
			'package'      => version_compare( $release['version'], $installiert, '>' ) ? $release['paket'] : '',
			'requires'     => (string) ( $plugin_daten['RequiresWP'] ?? '' ),
			'requires_php' => (string) ( $plugin_daten['RequiresPHP'] ?? '' ),
		);
	}

	/**
	 * Fenster „Details ansehen“ in der Plugin-Liste.
	 */
	public static function details( $ergebnis, $aktion, $argumente ) {
		if ( 'plugin_information' !== $aktion || self::SLUG !== ( $argumente->slug ?? '' ) ) {
			return $ergebnis;
		}
		$release = self::neuestes_release();
		$version = $release['version'] ?? FWB_VERSION;
		$text    = $release ? self::text_als_html( $release['text'] ) : '<p>GitHub ist gerade nicht erreichbar.</p>';

		return (object) array(
			'name'          => 'Feuerwehr Beladung',
			'slug'          => self::SLUG,
			'version'       => $version,
			'author'        => 'Freiwillige Feuerwehr',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'last_updated'  => $release['datum'] ?? '',
			'download_link' => $release['paket'] ?? '',
			'sections'      => array(
				'description' => '<p>Mitglieder lernen am Handy, welches Gerät in welchem Gerätefach der Fahrzeuge liegt.</p>'
					. '<p>Ein Update ersetzt nur den Programmcode. Fahrzeuge, Fotos und Lernstände bleiben erhalten.</p>',
				'changelog'   => $text,
			),
		);
	}

	/**
	 * Release-Text (Markdown) sehr einfach und sicher in HTML umwandeln:
	 * Überschriften, Aufzählungen, Absätze. Alles andere bleibt Text.
	 */
	public static function text_als_html( string $text ): string {
		$html  = '';
		$liste = false;
		foreach ( preg_split( '/\R/', trim( $text ) ) as $zeile ) {
			$zeile = trim( $zeile );
			if ( preg_match( '/^[-*]\s+(.*)$/', $zeile, $treffer ) ) {
				if ( ! $liste ) {
					$html .= '<ul>';
					$liste = true;
				}
				$html .= '<li>' . esc_html( $treffer[1] ) . '</li>';
				continue;
			}
			if ( $liste ) {
				$html .= '</ul>';
				$liste = false;
			}
			if ( '' === $zeile ) {
				continue;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $zeile, $treffer ) ) {
				$html .= '<h4>' . esc_html( $treffer[1] ) . '</h4>';
			} else {
				$html .= '<p>' . esc_html( $zeile ) . '</p>';
			}
		}
		if ( $liste ) {
			$html .= '</ul>';
		}
		return '' === $html ? '<p>Keine Angaben.</p>' : str_replace( '**', '', $html );
	}

	/**
	 * Absicherung: Heißt der entpackte Ordner anders als feuerwehr-beladung
	 * (z. B. weil jemand ein falsches ZIP erwischt hat), wird er umbenannt.
	 * Sonst würde WordPress das Plugin in einen neuen Ordner legen und es
	 * wäre danach deaktiviert.
	 */
	public static function ordner_richten( $quelle, $entpackt, $upgrader, $extra = array() ) {
		if ( is_wp_error( $quelle ) || self::basisname() !== ( $extra['plugin'] ?? '' ) ) {
			return $quelle;
		}
		global $wp_filesystem;
		$quelle = trailingslashit( $quelle );
		if ( ! file_exists( $quelle . 'feuerwehr-beladung.php' ) ) {
			return new \WP_Error( 'fwb_falsches_paket', 'Das Paket enthält das Plugin „Feuerwehr Beladung“ nicht. Update abgebrochen, das installierte Plugin bleibt unverändert.' );
		}
		$ziel = trailingslashit( $entpackt ) . self::SLUG . '/';
		if ( $quelle === $ziel ) {
			return $quelle;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $quelle, $ziel, true ) ) {
			return $ziel;
		}
		return new \WP_Error( 'fwb_umbenennen', 'Der Ordner des Updates konnte nicht umbenannt werden.' );
	}

	/**
	 * Link „Nach Updates suchen“ in der Plugin-Liste (nur Administratoren).
	 */
	public static function zeilen_links( $links, $datei ) {
		if ( self::basisname() !== $datei || ! current_user_can( 'update_plugins' ) || ! current_user_can( Rechte::VERWALTEN ) ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( self::such_link() ) . '">Nach Updates suchen</a>';
		return $links;
	}

	public static function such_link( string $zurueck = 'plugins' ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=fwb_update_suchen&zurueck=' . $zurueck ), 'fwb_update_suchen' );
	}

	/**
	 * Fragt GitHub sofort neu und lässt WordPress die Updates neu prüfen.
	 */
	public static function jetzt_suchen(): void {
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( Rechte::VERWALTEN ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		check_admin_referer( 'fwb_update_suchen' );

		$release = self::neuestes_release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		if ( null === $release ) {
			$ergebnis = 'fehler';
		} elseif ( version_compare( $release['version'], FWB_VERSION, '>' ) ) {
			$ergebnis = 'neu';
		} else {
			$ergebnis = 'aktuell';
		}
		set_transient( 'fwb_update_hinweis_' . get_current_user_id(), $ergebnis, MINUTE_IN_SECONDS );

		$zurueck = 'einstellungen' === ( $_GET['zurueck'] ?? '' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? admin_url( Admin_Menue::ELTERN . '&page=fwb-einstellungen' )
			: admin_url( 'plugins.php' );
		wp_safe_redirect( $zurueck );
		exit;
	}

	public static function hinweis(): void {
		$schluessel = 'fwb_update_hinweis_' . get_current_user_id();
		$ergebnis   = get_transient( $schluessel );
		if ( ! $ergebnis ) {
			return;
		}
		delete_transient( $schluessel );
		$release = self::neuestes_release();
		$texte   = array(
			'neu'     => array( 'warning', 'Version ' . ( $release['version'] ?? '' ) . ' von Feuerwehr Beladung ist verfügbar. Unter „Plugins“ auf „Jetzt aktualisieren“ klicken. Fahrzeuge, Fotos und Lernstände bleiben erhalten.' ),
			'aktuell' => array( 'success', 'Feuerwehr Beladung ist aktuell (Version ' . FWB_VERSION . ').' ),
			'fehler'  => array( 'error', 'GitHub war nicht erreichbar oder es gibt noch kein passendes Release. Bitte später noch einmal versuchen.' ),
		);
		if ( ! isset( $texte[ $ergebnis ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $texte[ $ergebnis ][0] ), esc_html( $texte[ $ergebnis ][1] ) );
	}

	/**
	 * Kasten für die Einstellungsseite.
	 */
	public static function stand_html(): string {
		$gemerkt = get_site_transient( self::ZWISCHENSPEICHER );
		$release = is_array( $gemerkt ) ? ( $gemerkt['release'] ?? null ) : null;
		$html    = 'Installiert: <strong>' . esc_html( FWB_VERSION ) . '</strong>';
		if ( $release ) {
			$html .= ' · Neueste auf GitHub: <strong>' . esc_html( $release['version'] ) . '</strong>';
			if ( version_compare( $release['version'], FWB_VERSION, '>' ) ) {
				$html .= ' – <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">jetzt unter „Plugins“ aktualisieren</a>';
			}
		}
		if ( current_user_can( 'update_plugins' ) ) {
			$html .= '<p><a class="button" href="' . esc_url( self::such_link( 'einstellungen' ) ) . '">Nach Updates suchen</a></p>';
		}
		$html .= '<p class="description">Updates kommen aus den Releases auf github.com/' . esc_html( self::REPO ) . '. Ein Update ersetzt nur den Programmcode; Fahrzeuge, Fotos und Lernstände bleiben erhalten.</p>';
		return $html;
	}
}
