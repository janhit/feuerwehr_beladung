<?php
/**
 * Datenmodell der Beladung eines Fahrzeugs und seine Bereinigung.
 *
 * Die Beladung ist ein strukturiertes Dokument (JSON). Positionen stehen in
 * Prozent des Fotos (0–100). Jede Eingabe läuft hier Feld für Feld durch:
 * Unbekanntes wird verworfen, Texte bereinigt, Zahlen begrenzt, Verweise
 * geprüft. Was entfernt wird, steht in den Hinweisen.
 *
 * Aufbau (Schema 1):
 *  steckbrief: { foto_id, kurz, felder: [ {label, wert, eckdatum} ] }
 *  ansichten:  [ {id, name, foto_id, skizze, bereiche: [ {id, fach_id, x, y, b, h} ]} ]
 *  faecher:    [ {id, kuerzel, name,
 *                 plaetze: [ {id, geraet, menge, lage, notiz} ],
 *                 bilder:  [ {id, titel, foto_id, skizze,
 *                             markierungen: [ {id, platz_id, x, y, b, h, notiz} ],
 *                             schalter:     [ {id, art, ziel_bild_id, x, y} ] } ] } ]
 *
 * "geraet" ist die feste Kennung (uid) eines Eintrags im Gerätekatalog.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION = 1;

	/** Mitgelieferte Skizzen (für das Beispielfahrzeug und Fahrzeuge ohne Fotos). */
	public const SKIZZEN = array(
		'seite-fahrer',
		'seite-beifahrer',
		'heck',
		'dach',
		'fach',
		'fach-schwenkwand-zu',
		'fach-schwenkwand-auf',
		'fach-auszug-zu',
		'fach-auszug-auf',
	);

	public const SCHALTER_ARTEN = array( 'schwenk', 'auszug' );

	/** Obergrenzen gegen versehentlich riesige Dokumente. */
	private const MAX = array(
		'felder'       => 20,
		'eckdaten'     => 3,
		'ansichten'    => 20,
		'bereiche'     => 80,
		'faecher'      => 80,
		'plaetze'      => 200,
		'bilder'       => 30,
		'markierungen' => 300,
		'schalter'     => 30,
	);

	private const ID_MUSTER     = '/^[a-z]_[a-z0-9]{4,20}$/';
	public const GERAET_MUSTER = '/^g_[a-z0-9]{4,20}$/';

	/** @var array<string,true> */
	private array $vergebene_ids = array();

	/** @var string[] */
	private array $hinweise = array();

	/** @var callable(int):bool */
	private $ist_bild;

	/** @var callable(string):bool */
	private $geraet_existiert;

	private function __construct( callable $ist_bild, callable $geraet_existiert ) {
		$this->ist_bild         = $ist_bild;
		$this->geraet_existiert = $geraet_existiert;
	}

	public static function leer(): array {
		return array(
			'schema'     => self::VERSION,
			'steckbrief' => array(
				'foto_id' => 0,
				'kurz'    => '',
				'felder'  => array(),
			),
			'ansichten'  => array(),
			'faecher'    => array(),
		);
	}

	/**
	 * Bereinigt ein Dokument.
	 *
	 * @param mixed                      $roh              Rohdaten (z. B. aus json_decode(..., true)).
	 * @param null|callable(int):bool    $ist_bild         Prüft, ob eine Anhang-ID ein Bild ist.
	 * @param null|callable(string):bool $geraet_existiert Prüft, ob eine Geräte-uid im Katalog steht.
	 * @return array{0: array, 1: string[]} Bereinigtes Dokument und Hinweise.
	 */
	public static function bereinigen( $roh, ?callable $ist_bild = null, ?callable $geraet_existiert = null ): array {
		$ist_bild         ??= static fn( int $id ): bool => $id > 0 && wp_attachment_is_image( $id );
		$geraet_existiert ??= array( Geraete_Katalog::class, 'existiert' );

		$s   = new self( $ist_bild, $geraet_existiert );
		$roh = is_array( $roh ) ? $roh : array();

		$dok               = self::leer();
		$dok['steckbrief'] = $s->steckbrief( $roh['steckbrief'] ?? array() );
		$dok['faecher']    = $s->faecher( $roh['faecher'] ?? array() );
		$dok['ansichten']  = $s->ansichten( $roh['ansichten'] ?? array(), $dok['faecher'] );

		return array( $dok, $s->hinweise );
	}

	/** Alle Geräte-uids, die in einem Dokument vorkommen. */
	public static function geraete_uids( array $dok ): array {
		$uids = array();
		foreach ( $dok['faecher'] ?? array() as $fach ) {
			foreach ( $fach['plaetze'] ?? array() as $platz ) {
				$uids[ $platz['geraet'] ] = true;
			}
		}
		return array_keys( $uids );
	}

	/** Kurze Zusammenfassung für das Protokoll. */
	public static function zusammenfassung( array $dok ): string {
		$plaetze = 0;
		$bilder  = 0;
		foreach ( $dok['faecher'] ?? array() as $fach ) {
			$plaetze += count( $fach['plaetze'] ?? array() );
			$bilder  += count( $fach['bilder'] ?? array() );
		}
		return sprintf(
			'%d Ansichten, %d Fächer, %d Fachbilder, %d Geräteplätze',
			count( $dok['ansichten'] ?? array() ),
			count( $dok['faecher'] ?? array() ),
			$bilder,
			$plaetze
		);
	}

	public static function neue_id( string $praefix ): string {
		return $praefix . '_' . strtolower( wp_generate_password( 10, false, false ) );
	}

	/* ------------------------------------------------------------------ */

	private function steckbrief( $roh ): array {
		$roh    = is_array( $roh ) ? $roh : array();
		$felder = array();
		$eck    = 0;
		foreach ( $this->liste( $roh['felder'] ?? null, 'felder' ) as $f ) {
			$label = $this->text( $f['label'] ?? '', 40 );
			if ( '' === $label ) {
				continue;
			}
			$eckdatum = ! empty( $f['eckdatum'] ) && $eck < self::MAX['eckdaten'];
			if ( $eckdatum ) {
				++$eck;
			} elseif ( ! empty( $f['eckdatum'] ) ) {
				$this->hinweis( 'Höchstens drei Eckdaten für die Fahrzeugkarte; „' . $label . '“ wird nicht auf der Karte gezeigt.' );
			}
			$felder[] = array(
				'label'    => $label,
				'wert'     => $this->text( $f['wert'] ?? '', 120 ),
				'eckdatum' => $eckdatum,
			);
		}
		return array(
			'foto_id' => $this->foto( $roh['foto_id'] ?? 0 ),
			'kurz'    => $this->mehrzeilig( $roh['kurz'] ?? '', 1000 ),
			'felder'  => $felder,
		);
	}

	private function faecher( $roh ): array {
		$faecher = array();
		foreach ( $this->liste( $roh, 'faecher' ) as $f ) {
			$fach = array(
				'id'      => $this->id( $f['id'] ?? '', 'f' ),
				'kuerzel' => $this->text( $f['kuerzel'] ?? '', 10 ),
				'name'    => $this->text( $f['name'] ?? '', 80 ),
				'plaetze' => array(),
				'bilder'  => array(),
			);
			if ( '' === $fach['kuerzel'] ) {
				$fach['kuerzel'] = 'G' . ( count( $faecher ) + 1 );
				$this->hinweis( 'Ein Fach hatte kein Kürzel und heißt jetzt „' . $fach['kuerzel'] . '“.' );
			}

			// Plätze: welches Katalog-Gerät liegt in diesem Fach.
			$platz_ids = array();
			$geraete   = array();
			foreach ( $this->liste( $f['plaetze'] ?? null, 'plaetze' ) as $p ) {
				$uid = is_string( $p['geraet'] ?? null ) ? $p['geraet'] : '';
				if ( ! preg_match( self::GERAET_MUSTER, $uid ) || ! ( $this->geraet_existiert )( $uid ) ) {
					$this->hinweis( 'In ' . $fach['kuerzel'] . ' wurde ein Gerät entfernt, das nicht (mehr) im Gerätekatalog steht.' );
					continue;
				}
				if ( isset( $geraete[ $uid ] ) ) {
					$this->hinweis( 'In ' . $fach['kuerzel'] . ' war ein Gerät doppelt eingetragen; der zweite Eintrag wurde entfernt.' );
					continue;
				}
				$geraete[ $uid ]   = true;
				$platz             = array(
					'id'     => $this->id( $p['id'] ?? '', 'p' ),
					'geraet' => $uid,
					'menge'  => $this->text( $p['menge'] ?? '', 30 ),
					'lage'   => $this->text( $p['lage'] ?? '', 80 ),
					'notiz'  => $this->mehrzeilig( $p['notiz'] ?? '', 500 ),
				);
				$platz_ids[ $platz['id'] ] = true;
				$fach['plaetze'][]         = $platz;
			}

			// Bilder: erst alle IDs festlegen, damit Schalter darauf zeigen können.
			$roh_bilder = $this->liste( $f['bilder'] ?? null, 'bilder' );
			$bild_ids   = array();
			foreach ( $roh_bilder as $i => $b ) {
				$bild_ids[ $i ] = $this->id( $b['id'] ?? '', 'b' );
			}
			$gueltige_bilder = array_flip( $bild_ids );

			foreach ( $roh_bilder as $i => $b ) {
				$bild = array(
					'id'           => $bild_ids[ $i ],
					'titel'        => $this->text( $b['titel'] ?? '', 60 ),
					'foto_id'      => $this->foto( $b['foto_id'] ?? 0 ),
					'skizze'       => $this->skizze( $b['skizze'] ?? '' ),
					'markierungen' => array(),
					'schalter'     => array(),
				);
				foreach ( $this->liste( $b['markierungen'] ?? null, 'markierungen' ) as $m ) {
					$platz_id = is_string( $m['platz_id'] ?? null ) ? $m['platz_id'] : '';
					if ( ! isset( $platz_ids[ $platz_id ] ) ) {
						$this->hinweis( 'Eine Markierung in ' . $fach['kuerzel'] . ' gehörte zu keinem Gerät des Fachs und wurde entfernt.' );
						continue;
					}
					$bild['markierungen'][] = array( 'id' => $this->id( $m['id'] ?? '', 'm' ), 'platz_id' => $platz_id ) + $this->rechteck( $m ) + array( 'notiz' => $this->text( $m['notiz'] ?? '', 60 ) );
				}
				foreach ( $this->liste( $b['schalter'] ?? null, 'schalter' ) as $sch ) {
					$ziel = is_string( $sch['ziel_bild_id'] ?? null ) ? $sch['ziel_bild_id'] : '';
					$art  = in_array( $sch['art'] ?? '', self::SCHALTER_ARTEN, true ) ? $sch['art'] : 'schwenk';
					if ( ! isset( $gueltige_bilder[ $ziel ] ) || $ziel === $bild['id'] ) {
						$this->hinweis( 'Ein Schalter in ' . $fach['kuerzel'] . ' zeigte auf kein anderes Bild des Fachs und wurde entfernt.' );
						continue;
					}
					$bild['schalter'][] = array(
						'id'           => $this->id( $sch['id'] ?? '', 's' ),
						'art'          => $art,
						'ziel_bild_id' => $ziel,
						'x'            => $this->prozent( $sch['x'] ?? 50 ),
						'y'            => $this->prozent( $sch['y'] ?? 50 ),
					);
				}
				if ( '' === $bild['titel'] ) {
					$bild['titel'] = 'Bild ' . ( count( $fach['bilder'] ) + 1 );
				}
				$fach['bilder'][] = $bild;
			}

			// Jedes Fach braucht mindestens ein Bild.
			if ( ! $fach['bilder'] ) {
				$fach['bilder'][] = array(
					'id'           => $this->id( '', 'b' ),
					'titel'        => 'Fach geöffnet',
					'foto_id'      => 0,
					'skizze'       => '',
					'markierungen' => array(),
					'schalter'     => array(),
				);
			}
			$faecher[] = $fach;
		}
		return $faecher;
	}

	private function ansichten( $roh, array $faecher ): array {
		$fach_ids = array();
		foreach ( $faecher as $fach ) {
			$fach_ids[ $fach['id'] ] = true;
		}
		$ansichten = array();
		foreach ( $this->liste( $roh, 'ansichten' ) as $a ) {
			$ansicht = array(
				'id'       => $this->id( $a['id'] ?? '', 'a' ),
				'name'     => $this->text( $a['name'] ?? '', 40 ),
				'foto_id'  => $this->foto( $a['foto_id'] ?? 0 ),
				'skizze'   => $this->skizze( $a['skizze'] ?? '' ),
				'bereiche' => array(),
			);
			if ( '' === $ansicht['name'] ) {
				$ansicht['name'] = 'Ansicht ' . ( count( $ansichten ) + 1 );
			}
			$schon = array();
			foreach ( $this->liste( $a['bereiche'] ?? null, 'bereiche' ) as $r ) {
				$fach_id = is_string( $r['fach_id'] ?? null ) ? $r['fach_id'] : '';
				if ( ! isset( $fach_ids[ $fach_id ] ) || isset( $schon[ $fach_id ] ) ) {
					$this->hinweis( 'In der Ansicht „' . $ansicht['name'] . '“ wurde eine Fachfläche ohne gültiges oder mit doppeltem Fach entfernt.' );
					continue;
				}
				$schon[ $fach_id ]       = true;
				$ansicht['bereiche'][] = array( 'id' => $this->id( $r['id'] ?? '', 'r' ), 'fach_id' => $fach_id ) + $this->rechteck( $r );
			}
			$ansichten[] = $ansicht;
		}
		return $ansichten;
	}

	/* ---------------------------- Bausteine ---------------------------- */

	/** Liefert nur Listen-Einträge, die Arrays sind, und begrenzt die Anzahl. */
	private function liste( $wert, string $art ): array {
		if ( ! is_array( $wert ) ) {
			return array();
		}
		$eintraege = array_values( array_filter( $wert, 'is_array' ) );
		if ( count( $eintraege ) > self::MAX[ $art ] ) {
			$this->hinweis( 'Zu viele Einträge (' . $art . '); es werden höchstens ' . self::MAX[ $art ] . ' gespeichert.' );
			$eintraege = array_slice( $eintraege, 0, self::MAX[ $art ] );
		}
		return $eintraege;
	}

	/** Behält eine gültige, noch freie ID, sonst wird eine neue erzeugt. */
	private function id( $wert, string $praefix ): string {
		$wert = is_string( $wert ) ? $wert : '';
		if ( ! preg_match( self::ID_MUSTER, $wert ) || ! str_starts_with( $wert, $praefix . '_' ) || isset( $this->vergebene_ids[ $wert ] ) ) {
			do {
				$wert = self::neue_id( $praefix );
			} while ( isset( $this->vergebene_ids[ $wert ] ) );
		}
		$this->vergebene_ids[ $wert ] = true;
		return $wert;
	}

	private function text( $wert, int $max ): string {
		$wert = is_scalar( $wert ) ? (string) $wert : '';
		return mb_substr( sanitize_text_field( $wert ), 0, $max );
	}

	private function mehrzeilig( $wert, int $max ): string {
		$wert = is_scalar( $wert ) ? (string) $wert : '';
		return mb_substr( sanitize_textarea_field( $wert ), 0, $max );
	}

	private function foto( $wert ): int {
		$id = is_numeric( $wert ) ? (int) $wert : 0;
		if ( $id <= 0 ) {
			return 0;
		}
		if ( ! ( $this->ist_bild )( $id ) ) {
			$this->hinweis( 'Ein ausgewähltes Foto gibt es nicht mehr in der Mediathek; es wurde entfernt.' );
			return 0;
		}
		return $id;
	}

	private function skizze( $wert ): string {
		return is_string( $wert ) && in_array( $wert, self::SKIZZEN, true ) ? $wert : '';
	}

	private function prozent( $wert ): float {
		$zahl = is_numeric( $wert ) ? (float) $wert : 0.0;
		if ( ! is_finite( $zahl ) ) {
			$zahl = 0.0;
		}
		return round( max( 0.0, min( 100.0, $zahl ) ), 2 );
	}

	/** Rechteck in Prozent; bleibt immer vollständig im Bild. */
	private function rechteck( array $r ): array {
		$b = max( 1.0, $this->prozent( $r['b'] ?? 10 ) );
		$h = max( 1.0, $this->prozent( $r['h'] ?? 10 ) );
		$x = min( $this->prozent( $r['x'] ?? 0 ), 100 - $b );
		$y = min( $this->prozent( $r['y'] ?? 0 ), 100 - $h );
		return array(
			'x' => round( $x, 2 ),
			'y' => round( $y, 2 ),
			'b' => round( $b, 2 ),
			'h' => round( $h, 2 ),
		);
	}

	private function hinweis( string $text ): void {
		if ( ! in_array( $text, $this->hinweise, true ) ) {
			$this->hinweise[] = $text;
		}
	}
}
