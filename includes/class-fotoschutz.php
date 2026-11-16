<?php
/**
 * Fotoschutz: Fotos der Beladung sind nur für angemeldete Nutzer abrufbar.
 *
 * Ein geschütztes Foto (Anhang der Mediathek) wird mit allen Größen in einen
 * eigenen Ordner verschoben:  uploads/fwb-geschuetzt/<zufall>/<datei>
 * - Der Ordnername ist zufällig (32 Zeichen) und erscheint nirgends.
 * - .htaccess sperrt den Ordner auf Apache; bei nginx greift das nicht,
 *   der Selbsttest in den Einstellungen sagt dann, was zu tun ist.
 * - Alle Adressen zeigen auf  /?fwb_bild=<id>&d=/<datei> . Das Plugin prüft
 *   dort die Anmeldung und liefert die Datei aus.
 * - Die Medien-Schnittstelle von WordPress und Anhang-Seiten verraten
 *   geschützte Fotos nicht an Unangemeldete.
 *
 * Automatisch geschützt werden Fotos, die im Fahrzeug-Editor hochgeladen
 * wurden (Anhang gehört zum Fahrzeug). Andere Fotos aus der Mediathek nur auf
 * Knopfdruck, weil sie vielleicht schon in einem Beitrag stecken.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Fotoschutz {

	public const ORDNER = 'fwb-geschuetzt';
	public const META   = '_fwb_geschuetzt';

	public static function haken(): void {
		add_action( 'init', array( self::class, 'ausliefern' ), 1 );
		add_filter( 'wp_get_attachment_url', array( self::class, 'adresse' ), 20, 2 );
		add_filter( 'rest_attachment_query', array( self::class, 'rest_liste' ), 10, 2 );
		add_filter( 'rest_prepare_attachment', array( self::class, 'rest_einzeln' ), 10, 3 );
		add_action( 'template_redirect', array( self::class, 'anhang_seite' ) );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'srcset' ), 10, 5 );
		add_action( 'delete_attachment', array( self::class, 'ordner_merken' ) );
		// clean_post_cache läuft beim Löschen erst nach dem Entfernen der Dateien.
		add_action( 'clean_post_cache', array( self::class, 'leeren_ordner_entfernen' ) );
	}

	/** @var array<int, string> */
	private static array $zu_loeschen = array();

	/** Vor dem Löschen eines geschützten Fotos: seinen Zufallsordner merken. */
	public static function ordner_merken( int $id ): void {
		if ( self::ist_geschuetzt( $id ) ) {
			self::$zu_loeschen[ $id ] = dirname( (string) get_attached_file( $id, true ) );
		}
	}

	/** Nach dem Löschen: leeren Zufallsordner entfernen (nur innerhalb des geschützten Ordners). */
	public static function leeren_ordner_entfernen( int $id ): void {
		if ( ! isset( self::$zu_loeschen[ $id ] ) ) {
			return;
		}
		$dir = self::$zu_loeschen[ $id ];
		unset( self::$zu_loeschen[ $id ] );
		$basis = realpath( self::ordner()['pfad'] );
		$echt  = realpath( $dir );
		if ( $basis && $echt && str_starts_with( $echt, $basis . DIRECTORY_SEPARATOR ) && is_dir( $echt ) && ! ( new \FilesystemIterator( $echt ) )->valid() ) {
			@rmdir( $echt ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/* ------------------------------ Ordner ------------------------------ */

	/** @return array{pfad:string, url:string} */
	public static function ordner(): array {
		$uploads = wp_get_upload_dir();
		return array(
			'pfad' => trailingslashit( $uploads['basedir'] ) . self::ORDNER,
			'url'  => trailingslashit( $uploads['baseurl'] ) . self::ORDNER,
		);
	}

	/** Legt den Ordner mit Sperrdateien an (mehrfach aufrufbar). */
	public static function ordner_vorbereiten(): bool {
		$pfad = self::ordner()['pfad'];
		if ( ! wp_mkdir_p( $pfad ) ) {
			return false;
		}
		$htaccess = "# Feuerwehr Beladung: kein direkter Zugriff.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		if ( ! file_exists( $pfad . '/.htaccess' ) ) {
			file_put_contents( $pfad . '/.htaccess', $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $pfad . '/index.php' ) ) {
			file_put_contents( $pfad . '/index.php', "<?php\n// Stille.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	/* ------------------------------ Schützen ------------------------------ */

	public static function ist_geschuetzt( int $id ): bool {
		return (bool) get_post_meta( $id, self::META, true );
	}

	/**
	 * Darf ein Foto ohne Rückfrage geschützt werden? Ja, wenn es zu einem
	 * Fahrzeug hochgeladen wurde (dann steckt es in keinem Beitrag).
	 */
	public static function automatisch_erlaubt( int $id ): bool {
		$eltern = (int) wp_get_post_parent_id( $id );
		return $eltern && Fahrzeug_Typ::TYP === get_post_type( $eltern );
	}

	/**
	 * Verschiebt ein Bild mit allen Größen in den geschützten Ordner.
	 *
	 * @return true|\WP_Error
	 */
	public static function schuetzen( int $id ) {
		if ( ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'fwb_kein_bild', 'Das ist kein Bild aus der Mediathek.' );
		}
		if ( self::ist_geschuetzt( $id ) ) {
			return true;
		}
		$alt = get_attached_file( $id, true );
		if ( ! $alt || ! file_exists( $alt ) ) {
			return new \WP_Error( 'fwb_datei_fehlt', 'Die Bilddatei wurde nicht gefunden.' );
		}
		if ( ! self::ordner_vorbereiten() ) {
			return new \WP_Error( 'fwb_ordner', 'Der geschützte Ordner konnte nicht angelegt werden.' );
		}

		$zufall   = strtolower( wp_generate_password( 32, false ) );
		$ziel_dir = self::ordner()['pfad'] . '/' . $zufall;
		if ( ! wp_mkdir_p( $ziel_dir ) ) {
			return new \WP_Error( 'fwb_ordner', 'Der geschützte Ordner konnte nicht angelegt werden.' );
		}

		$meta    = wp_get_attachment_metadata( $id );
		$meta    = is_array( $meta ) ? $meta : array();
		$alt_dir = dirname( $alt );
		$dateien = array( wp_basename( $alt ) );
		foreach ( $meta['sizes'] ?? array() as $groesse ) {
			if ( ! empty( $groesse['file'] ) ) {
				$dateien[] = wp_basename( $groesse['file'] );
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$dateien[] = wp_basename( $meta['original_image'] );
		}

		$verschoben = array();
		foreach ( array_unique( $dateien ) as $datei ) {
			$von = $alt_dir . '/' . $datei;
			if ( ! file_exists( $von ) ) {
				continue;
			}
			$nach = $ziel_dir . '/' . $datei;
			if ( ! @rename( $von, $nach ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! @copy( $von, $nach ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					// Rückgängig machen, was schon verschoben ist.
					foreach ( $verschoben as $paar ) {
						@rename( $paar[1], $paar[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					return new \WP_Error( 'fwb_verschieben', 'Das Bild konnte nicht verschoben werden (Dateirechte?).' );
				}
				wp_delete_file( $von );
			}
			$verschoben[] = array( $von, $nach );
		}

		$relativ = self::ORDNER . '/' . $zufall . '/' . wp_basename( $alt );
		update_post_meta( $id, '_wp_attached_file', $relativ );
		if ( $meta ) {
			$meta['file'] = $relativ;
			wp_update_attachment_metadata( $id, $meta );
		}
		update_post_meta( $id, self::META, 1 );
		clean_post_cache( $id );
		return true;
	}

	/** Alle Foto-IDs eines Beladungs-Dokuments. @return int[] */
	public static function fotos_im_dokument( array $dok ): array {
		$ids = array( (int) ( $dok['steckbrief']['foto_id'] ?? 0 ) );
		foreach ( $dok['ansichten'] ?? array() as $a ) {
			$ids[] = (int) ( $a['foto_id'] ?? 0 );
		}
		foreach ( $dok['faecher'] ?? array() as $f ) {
			foreach ( $f['bilder'] ?? array() as $b ) {
				$ids[] = (int) ( $b['foto_id'] ?? 0 );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Nach dem Speichern eines Fahrzeugs: zum Fahrzeug hochgeladene Fotos schützen.
	 *
	 * @return array{geschuetzt:int, offen:int[]} offen = Fotos, die nur auf Knopfdruck geschützt werden.
	 */
	public static function dokument_schuetzen( array $dok ): array {
		$ergebnis = array( 'geschuetzt' => 0, 'offen' => array() );
		foreach ( self::fotos_im_dokument( $dok ) as $id ) {
			if ( self::ist_geschuetzt( $id ) ) {
				continue;
			}
			if ( self::automatisch_erlaubt( $id ) && true === self::schuetzen( $id ) ) {
				++$ergebnis['geschuetzt'];
			} else {
				$ergebnis['offen'][] = $id;
			}
		}
		return $ergebnis;
	}

	/* ------------------------------ Adressen ------------------------------ */

	/**
	 * Adresse zum Ausliefern. Die Datei steht am Ende hinter „/“, damit
	 * WordPress daraus wie gewohnt die Adressen der anderen Größen bildet.
	 */
	public static function bild_url( int $id, string $datei ): string {
		return home_url( '/' ) . '?fwb_bild=' . $id . '&d=/' . rawurlencode( wp_basename( $datei ) );
	}

	public static function adresse( $url, $id ) {
		if ( ! self::ist_geschuetzt( (int) $id ) ) {
			return $url;
		}
		$datei = get_post_meta( (int) $id, '_wp_attached_file', true );
		return $datei ? self::bild_url( (int) $id, (string) $datei ) : $url;
	}

	/** srcset: WordPress setzt sonst den Upload-Pfad davor. */
	public static function srcset( $quellen, $groesse, $src, $meta, $id ) {
		if ( ! is_array( $quellen ) || ! self::ist_geschuetzt( (int) $id ) ) {
			return $quellen;
		}
		foreach ( $quellen as $breite => $q ) {
			$quellen[ $breite ]['url'] = self::bild_url( (int) $id, wp_basename( (string) $q['url'] ) );
		}
		return $quellen;
	}

	/* ------------------------------ Ausliefern ------------------------------ */

	/** Alle Dateinamen eines geschützten Anhangs. @return string[] */
	public static function erlaubte_dateien( int $id ): array {
		$meta    = wp_get_attachment_metadata( $id );
		$dateien = array( wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) );
		foreach ( ( is_array( $meta ) ? $meta['sizes'] ?? array() : array() ) as $g ) {
			$dateien[] = wp_basename( (string) ( $g['file'] ?? '' ) );
		}
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$dateien[] = wp_basename( (string) $meta['original_image'] );
		}
		return array_values( array_filter( array_unique( $dateien ) ) );
	}

	/**
	 * Prüft eine Anfrage und gibt den Pfad der Datei zurück – oder einen
	 * HTTP-Status (403 nicht angemeldet, 404 gibt es nicht).
	 *
	 * @return string|int
	 */
	public static function datei_fuer_anfrage( int $id, string $datei ) {
		if ( ! Plugin::darf_lernen() ) {
			return 403;
		}
		$datei = wp_basename( $datei );
		if ( ! $id || '' === $datei || ! self::ist_geschuetzt( $id ) || ! in_array( $datei, self::erlaubte_dateien( $id ), true ) ) {
			return 404;
		}
		$pfad = dirname( (string) get_attached_file( $id, true ) ) . '/' . $datei;
		$echt = realpath( $pfad );
		$basis = realpath( self::ordner()['pfad'] );
		if ( ! $echt || ! $basis || ! str_starts_with( $echt, $basis . DIRECTORY_SEPARATOR ) || ! is_file( $echt ) ) {
			return 404;
		}
		return $echt;
	}

	public static function ausliefern(): void {
		if ( ! isset( $_GET['fwb_bild'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id    = absint( $_GET['fwb_bild'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$datei = isset( $_GET['d'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['d'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$ergebnis = self::datei_fuer_anfrage( $id, $datei );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( is_int( $ergebnis ) ) {
			status_header( $ergebnis );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 403 === $ergebnis ? 'Bitte anmelden.' : 'Nicht gefunden.';
			exit;
		}

		$typ  = wp_check_filetype( $ergebnis );
		$zeit = (int) filemtime( $ergebnis );
		$etag = '"' . md5( $ergebnis . $zeit . filesize( $ergebnis ) ) . '"';
		// Nur im Browser des Mitglieds zwischenspeichern, nie in fremden Caches.
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $zeit ) . ' GMT' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: ' . ( $typ['type'] ? $typ['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $ergebnis ) );
		readfile( $ergebnis ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ------------------------------ Nichts verraten ------------------------------ */

	public static function rest_liste( array $args, \WP_REST_Request $anfrage ): array {
		if ( Plugin::darf_bearbeiten() ) {
			return $args;
		}
		$args['meta_query']   = (array) ( $args['meta_query'] ?? array() );
		$args['meta_query'][] = array(
			'key'     => self::META,
			'compare' => 'NOT EXISTS',
		);
		return $args;
	}

	public static function rest_einzeln( $antwort, $post, $anfrage ) {
		if ( self::ist_geschuetzt( (int) $post->ID ) && ! Plugin::darf_bearbeiten() ) {
			// Wie ein nicht vorhandenes Foto antworten (ein WP_Error führte hier zu 500).
			return new \WP_REST_Response( array( 'code' => 'rest_post_invalid_id', 'message' => 'Ungültige ID.', 'data' => array( 'status' => 404 ) ), 404 );
		}
		return $antwort;
	}

	public static function anhang_seite(): void {
		if ( is_attachment() && self::ist_geschuetzt( (int) get_queried_object_id() ) && ! Plugin::darf_lernen() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
		}
	}

	/* ------------------------------ Selbsttest ------------------------------ */

	/**
	 * Prüft von außen (ohne Anmeldung):
	 * 1. Ist der Ordner direkt erreichbar?  2. Liefert das Plugin ohne Anmeldung aus?
	 *
	 * @return array{ordner_gesperrt:?bool, auslieferung_gesperrt:?bool, hinweise:string[]}
	 */
	public static function selbsttest(): array {
		$ergebnis = array( 'ordner_gesperrt' => null, 'auslieferung_gesperrt' => null, 'hinweise' => array() );
		if ( ! self::ordner_vorbereiten() ) {
			$ergebnis['hinweise'][] = 'Der Ordner „uploads/' . self::ORDNER . '“ konnte nicht angelegt werden.';
			return $ergebnis;
		}
		$name  = 'selbsttest-' . strtolower( wp_generate_password( 12, false ) ) . '.txt';
		$pfad  = self::ordner()['pfad'] . '/' . $name;
		$text  = 'fwb-selbsttest';
		file_put_contents( $pfad, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$antwort = wp_remote_get(
			self::ordner()['url'] . '/' . $name,
			array( 'timeout' => 8, 'redirection' => 0, 'cookies' => array() )
		);
		wp_delete_file( $pfad );

		if ( is_wp_error( $antwort ) ) {
			$ergebnis['hinweise'][] = 'Der Server konnte sich selbst nicht erreichen (' . $antwort->get_error_message() . '). Der Test ist deshalb nicht aussagekräftig.';
		} else {
			$offen                       = 200 === (int) wp_remote_retrieve_response_code( $antwort ) && $text === trim( (string) wp_remote_retrieve_body( $antwort ) );
			$ergebnis['ordner_gesperrt'] = ! $offen;
		}

		// Auslieferung ohne Anmeldung: muss 403 liefern.
		$antwort = wp_remote_get( home_url( '/' ) . '?fwb_bild=1&d=/test.jpg', array( 'timeout' => 8, 'redirection' => 0, 'cookies' => array() ) );
		if ( ! is_wp_error( $antwort ) ) {
			$ergebnis['auslieferung_gesperrt'] = 403 === (int) wp_remote_retrieve_response_code( $antwort );
		}
		return $ergebnis;
	}

	/** Anzahl geschützter Fotos (für die Einstellungen). */
	public static function anzahl(): int {
		$q = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $q->found_posts;
	}
}
