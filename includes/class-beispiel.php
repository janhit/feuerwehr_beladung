<?php
/**
 * Beispielfahrzeug mit erfundener Beladung, einer Schwenkwand (G1) und
 * einem Auszug (G2). Funktioniert ohne Fotos: Es nutzt mitgelieferte Skizzen.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Beispiel {

	public static function haken(): void {
		add_action( 'admin_post_fwb_beispiel', array( self::class, 'anlegen_anfrage' ) );
		add_action( 'admin_notices', array( self::class, 'knopf' ) );
	}

	/** Knopf über der Fahrzeugliste. */
	public static function knopf(): void {
		$bildschirm = get_current_screen();
		if ( ! $bildschirm || 'edit-' . Fahrzeug_Typ::TYP !== $bildschirm->id || ! current_user_can( Rechte::BEARBEITEN ) ) {
			return;
		}
		if ( isset( $_GET['fwb_beispiel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Das Beispielfahrzeug wurde als Entwurf angelegt.</p></div>';
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=fwb_beispiel' ), 'fwb_beispiel' );
		echo '<div class="notice notice-info"><p>Zum Ausprobieren: <a class="button" href="' . esc_url( $url ) . '">Beispielfahrzeug anlegen</a> ';
		echo '<span class="description">Erfundene Beladung mit Schwenkwand und Auszug, ohne echte Fotos. Wird als Entwurf angelegt.</span></p></div>';
	}

	public static function anlegen_anfrage(): void {
		if ( ! current_user_can( Rechte::BEARBEITEN ) ) {
			wp_die( esc_html( 'Keine Berechtigung.' ) );
		}
		check_admin_referer( 'fwb_beispiel' );
		self::anlegen();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Fahrzeug_Typ::TYP . '&fwb_beispiel=1' ) );
		exit;
	}

	/** @return int ID des neuen Fahrzeugs */
	public static function anlegen(): int {
		$zuordnung = array();
		foreach ( self::geraete() as $uid => [ $name, $beschreibung, $hinweise, $suche ] ) {
			$zuordnung[ $uid ] = Geraete_Katalog::anlegen( $name, $beschreibung, $hinweise, $suche, $uid );
		}
		$post_id = Protokoll::ohne_statuseintraege(
			static fn() => wp_insert_post(
				array(
					'post_type'   => Fahrzeug_Typ::TYP,
					'post_status' => 'draft',
					'post_title'  => 'HLF Beispiel',
				)
			)
		);
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return 0;
		}
		$dok = self::dokument();
		foreach ( $dok['faecher'] as &$f ) {
			foreach ( $f['plaetze'] as &$p ) {
				$p['geraet'] = $zuordnung[ $p['geraet'] ] ?? $p['geraet'];
			}
			unset( $p );
		}
		unset( $f );
		$ergebnis = Beladung::speichern( (int) $post_id, $dok );
		Protokoll::eintragen( 'fahrzeug', (int) $post_id, 'Beispiel', 'Beispielfahrzeug angelegt: ' . Schema::zusammenfassung( $ergebnis['dokument'] ) );
		return (int) $post_id;
	}

	/**
	 * Erfundene Geräte. Feste Kennungen, damit mehrfaches Anlegen keine Doppel erzeugt.
	 * Wenn ein Gerät mit gleichem Namen schon im Katalog steht, wird das vorhandene genutzt.
	 */
	private static function geraete(): array {
		return array(
			'g_bspleine'    => array( 'Feuerwehrleine', 'Leine im Leinenbeutel, 30 m.', 'Vor dem Einsatz auf Beschädigungen prüfen.', 'Leine, Fangleine' ),
			'g_bsplampe'    => array( 'Handlampe', 'Akku-Handlampe mit Ladehalterung.', 'Nach dem Einsatz wieder in die Ladehalterung stecken.', 'Lampe, Leuchte' ),
			'g_bsphydr'     => array( 'Hydrantenschlüssel', 'Schlüssel für Unterflurhydranten.', 'Immer zusammen mit dem Standrohr mitnehmen.', 'Hydrant, Schlüssel' ),
			'g_bspstand'    => array( 'Standrohr', 'Standrohr 2 B für Unterflurhydranten.', 'Vor dem Aufsetzen Hydrant kurz spülen.', 'Hydrant' ),
			'g_bspkuppl'    => array( 'Kupplungsschlüssel', 'Schlüssel für A-, B- und C-Kupplungen.', 'Je Trupp einen mitnehmen.', 'Schlüssel, Kupplung' ),
			'g_bspsammel'   => array( 'Sammelstück', 'Führt zwei B-Leitungen zu einer A-Leitung zusammen.', '', 'Sammler' ),
			'g_bspvert'     => array( 'Verteiler', 'B-Verteiler mit drei Abgängen (C-B-C).', 'Möglichst nah an der Einsatzstelle setzen.', 'Verteiler, CBC' ),
			'g_bsphkissen'  => array( 'Hebekissen-Satz', 'Drei Hebekissen mit Steuerorgan und Schläuchen.', 'Nie ohne Unterbau anheben, immer nachbauen.', 'Hebekissen, Kissen' ),
			'g_bspaggr'     => array( 'Hydraulikaggregat', 'Motor-Pumpe für Schere und Spreizer.', 'Vor dem Start Schläuche anschließen.', 'Aggregat, Hydraulik' ),
			'g_bspschere'   => array( 'Rettungsschere', 'Hydraulische Schere für die technische Hilfe.', 'Schutzbrille und Handschuhe tragen.', 'Schere, Schneidgerät' ),
			'g_bspschaufel' => array( 'Schaufel', 'Stielschaufel.', '', 'Schaufel, Spaten' ),
			'g_bsppa'       => array( 'Pressluftatmer', 'Atemschutzgerät mit 300-bar-Flasche.', 'Einsatzkurzprüfung vor dem Anlegen.', 'PA, Atemschutz' ),
			'g_bspmaske'    => array( 'Atemschutzmaske', 'Vollmaske im Maskenbeutel.', '', 'Maske, Atemschutz' ),
			'g_bspbschl'    => array( 'B-Schlauch', 'Druckschlauch B, 20 m.', '', 'Schlauch, B' ),
			'g_bspcschl'    => array( 'C-Schlauch', 'Druckschlauch C, 15 m.', '', 'Schlauch, C' ),
			'g_bspstrom'    => array( 'Stromerzeuger', 'Tragbarer Stromerzeuger, 5 kVA.', 'Nur im Freien betreiben.', 'Generator, Strom' ),
			'g_bspkabel'    => array( 'Kabeltrommel', 'Kabeltrommel 50 m, 230 V.', 'Bei Belastung ganz abwickeln.', 'Kabel, Verlängerung' ),
			'g_bspehr'      => array( 'Erste-Hilfe-Rucksack', 'Rucksack mit Verbandmaterial.', '', 'Sanitätsrucksack, Verband' ),
			'g_bspspine'    => array( 'Spineboard', 'Rettungsbrett mit Gurten und Kopffixierung.', '', 'Rettungsbrett' ),
			'g_bspsaug'     => array( 'Saugschlauch', 'Saugschlauch A, 1,6 m.', 'Immer mit Saugkorb verwenden.', 'Saugen, A' ),
			'g_bsphsr'      => array( 'Hohlstrahlrohr', 'C-Hohlstrahlrohr.', '', 'Strahlrohr, Rohr' ),
			'g_bsploescher' => array( 'Feuerlöscher', 'Pulverlöscher 12 kg.', '', 'Löscher, Pulver' ),
			'g_bspleiter'   => array( 'Steckleiter', 'Vierteilige Steckleiter.', 'Nur mit zwei Personen abnehmen.', 'Leiter' ),
			'g_bsptrage'    => array( 'Krankentrage', 'Klappbare Krankentrage.', '', 'Trage' ),
		);
	}

	/** Baut das Beispieldokument. Positionen passen zu den mitgelieferten Skizzen. */
	private static function dokument(): array {
		$fach = static function ( string $kuerzel, string $name, array $bilder, array $plaetze ): array {
			$k        = strtolower( $kuerzel );
			$p_liste  = array();
			$b_liste  = array();
			foreach ( $plaetze as $i => [ $uid, $menge, $lage ] ) {
				$p_liste[] = array( 'id' => 'p_bsp' . $k . $i, 'geraet' => $uid, 'menge' => $menge, 'lage' => $lage, 'notiz' => '' );
			}
			foreach ( $bilder as $j => $bild ) {
				$marken = array();
				foreach ( $bild['marken'] as $m => [ $platz_index, $x, $y, $b, $h ] ) {
					$marken[] = array( 'id' => 'm_bsp' . $k . $j . 'n' . $m, 'platz_id' => 'p_bsp' . $k . $platz_index, 'x' => $x, 'y' => $y, 'b' => $b, 'h' => $h );
				}
				$schalter = array();
				foreach ( $bild['schalter'] ?? array() as $s => [ $art, $ziel, $x, $y ] ) {
					$schalter[] = array( 'id' => 's_bsp' . $k . $j . 'n' . $s, 'art' => $art, 'ziel_bild_id' => 'b_bsp' . $k . $ziel, 'x' => $x, 'y' => $y );
				}
				$b_liste[] = array(
					'id'           => 'b_bsp' . $k . $j,
					'titel'        => $bild['titel'],
					'foto_id'      => 0,
					'skizze'       => $bild['skizze'],
					'markierungen' => $marken,
					'schalter'     => $schalter,
				);
			}
			return array( 'id' => 'f_bsp' . $k, 'kuerzel' => $kuerzel, 'name' => $name, 'plaetze' => $p_liste, 'bilder' => $b_liste );
		};
		$einfach = static fn( array $marken ): array => array( array( 'titel' => 'Fach geöffnet', 'skizze' => 'fach', 'marken' => $marken ) );

		$faecher = array(
			$fach(
				'G1',
				'Geräteraum 1 (Wasserentnahme)',
				array(
					array(
						'titel'    => 'Schwenkwand geschlossen',
						'skizze'   => 'fach-schwenkwand-zu',
						'marken'   => array( array( 0, 7, 70, 25, 20 ), array( 1, 8, 15, 15, 18 ), array( 2, 44, 22, 12, 32 ), array( 3, 64, 12, 10, 66 ) ),
						'schalter' => array( array( 'schwenk', 1, 52, 86 ) ),
					),
					array(
						'titel'    => 'Schwenkwand geöffnet',
						'skizze'   => 'fach-schwenkwand-auf',
						'marken'   => array( array( 0, 7, 70, 25, 20 ), array( 1, 8, 15, 15, 18 ), array( 4, 41, 20, 13, 24 ), array( 5, 41, 52, 13, 24 ), array( 6, 70, 55, 24, 30 ) ),
						'schalter' => array( array( 'schwenk', 0, 50, 88 ) ),
					),
				),
				array(
					array( 'g_bspleine', '1', 'Fachboden links' ),
					array( 'g_bsplampe', '1', 'oben links' ),
					array( 'g_bsphydr', '1', 'Schwenkwand, Vorderseite' ),
					array( 'g_bspstand', '1', 'Schwenkwand, Vorderseite' ),
					array( 'g_bspkuppl', '2', 'Schwenkwand, Rückseite' ),
					array( 'g_bspsammel', '1', 'Schwenkwand, Rückseite' ),
					array( 'g_bspvert', '1', 'hinter der Schwenkwand' ),
				)
			),
			$fach(
				'G2',
				'Geräteraum 2 (Technische Hilfe)',
				array(
					array(
						'titel'    => 'Auszug eingeschoben',
						'skizze'   => 'fach-auszug-zu',
						'marken'   => array( array( 0, 10, 10, 35, 30 ), array( 3, 60, 10, 30, 30 ) ),
						'schalter' => array( array( 'auszug', 1, 50, 82 ) ),
					),
					array(
						'titel'    => 'Auszug herausgezogen',
						'skizze'   => 'fach-auszug-auf',
						'marken'   => array( array( 0, 10, 10, 35, 30 ), array( 3, 60, 10, 30, 30 ), array( 1, 15, 70, 32, 20 ), array( 2, 55, 72, 30, 16 ) ),
						'schalter' => array( array( 'auszug', 0, 50, 95 ) ),
					),
				),
				array(
					array( 'g_bsphkissen', '1 Satz', 'oben links' ),
					array( 'g_bspaggr', '1', 'Auszug, links' ),
					array( 'g_bspschere', '1', 'Auszug, rechts' ),
					array( 'g_bspschaufel', '2', 'oben rechts' ),
				)
			),
			$fach(
				'G3',
				'Geräteraum 3 (Atemschutz)',
				$einfach( array( array( 0, 10, 15, 35, 55 ), array( 1, 55, 15, 35, 30 ), array( 2, 55, 60, 30, 25 ) ) ),
				array(
					array( 'g_bsppa', '2', 'links' ),
					array( 'g_bspmaske', '4', 'rechts oben' ),
					array( 'g_bspleine', '2', 'rechts unten' ),
				)
			),
			$fach(
				'G4',
				'Geräteraum 4 (Schläuche)',
				$einfach( array( array( 0, 8, 15, 40, 60 ), array( 1, 52, 15, 40, 60 ) ) ),
				array(
					array( 'g_bspbschl', '4', 'links' ),
					array( 'g_bspcschl', '6', 'rechts' ),
				)
			),
			$fach(
				'G5',
				'Geräteraum 5 (Strom)',
				$einfach( array( array( 0, 10, 50, 45, 40 ), array( 1, 60, 55, 30, 35 ), array( 2, 60, 15, 15, 20 ) ) ),
				array(
					array( 'g_bspstrom', '1', 'Fachboden links' ),
					array( 'g_bspkabel', '2', 'Fachboden rechts' ),
					array( 'g_bsplampe', '2', 'oben rechts' ),
				)
			),
			$fach(
				'G6',
				'Geräteraum 6 (Sanität)',
				$einfach( array( array( 0, 10, 15, 30, 40 ), array( 1, 50, 10, 40, 75 ) ) ),
				array(
					array( 'g_bspehr', '1', 'links' ),
					array( 'g_bspspine', '1', 'rechts, senkrecht' ),
				)
			),
			$fach(
				'GR',
				'Geräteraum Heck (Pumpe)',
				$einfach( array( array( 0, 10, 10, 80, 20 ), array( 1, 15, 45, 20, 20 ), array( 2, 60, 45, 15, 35 ) ) ),
				array(
					array( 'g_bspsaug', '4', 'oben quer' ),
					array( 'g_bsphsr', '2', 'Mitte links' ),
					array( 'g_bsploescher', '1', 'Mitte rechts' ),
				)
			),
			$fach( 'D1', 'Dachkasten vorne', $einfach( array( array( 0, 5, 20, 90, 25 ) ) ), array( array( 'g_bspleiter', '1 Satz', 'längs' ) ) ),
			$fach( 'D2', 'Dachkasten hinten', $einfach( array( array( 0, 5, 25, 90, 20 ) ) ), array( array( 'g_bsptrage', '1', 'längs' ) ) ),
		);

		$bereich = static fn( string $fach, float $x, float $y, float $b, float $h ): array => array(
			'id'      => 'r_bsp' . strtolower( $fach ),
			'fach_id' => 'f_bsp' . strtolower( $fach ),
			'x'       => $x,
			'y'       => $y,
			'b'       => $b,
			'h'       => $h,
		);

		return array(
			'schema'     => Schema::VERSION,
			'steckbrief' => array(
				'foto_id' => 0,
				'kurz'    => "Erfundenes Beispielfahrzeug zum Ausprobieren.\nAlle Fächer und Geräte sind ausgedacht. G1 hat eine Schwenkwand, G2 einen Auszug.",
				'felder'  => array(
					array( 'label' => 'Fahrzeugtyp', 'wert' => 'HLF 20', 'eckdatum' => true ),
					array( 'label' => 'Funkrufname', 'wert' => 'Florian Musterstadt 1/46-1', 'eckdatum' => true ),
					array( 'label' => 'Besatzung', 'wert' => '1/8', 'eckdatum' => true ),
					array( 'label' => 'Pumpe', 'wert' => 'FPN 10-2000', 'eckdatum' => false ),
					array( 'label' => 'Löschwassertank', 'wert' => '2000 l', 'eckdatum' => false ),
					array( 'label' => 'Schaummittel', 'wert' => '120 l', 'eckdatum' => false ),
					array( 'label' => 'Baujahr', 'wert' => '2019', 'eckdatum' => false ),
				),
			),
			'ansichten'  => array(
				array(
					'id'       => 'a_bspfahrer',
					'name'     => 'Fahrerseite',
					'foto_id'  => 0,
					'skizze'   => 'seite-fahrer',
					'bereiche' => array( $bereich( 'G1', 26.67, 20, 16.67, 55 ), $bereich( 'G3', 45, 20, 23.33, 55 ), $bereich( 'G5', 70, 20, 24.17, 55 ) ),
				),
				array(
					'id'       => 'a_bspbeifahrer',
					'name'     => 'Beifahrerseite',
					'foto_id'  => 0,
					'skizze'   => 'seite-beifahrer',
					'bereiche' => array( $bereich( 'G6', 5.83, 20, 24.17, 55 ), $bereich( 'G4', 31.67, 20, 23.33, 55 ), $bereich( 'G2', 56.67, 20, 16.67, 55 ) ),
				),
				array(
					'id'       => 'a_bspheck',
					'name'     => 'Heck',
					'foto_id'  => 0,
					'skizze'   => 'heck',
					'bereiche' => array( $bereich( 'GR', 31.67, 15, 36.67, 60 ) ),
				),
				array(
					'id'       => 'a_bspdach',
					'name'     => 'Dach',
					'foto_id'  => 0,
					'skizze'   => 'dach',
					'bereiche' => array( $bereich( 'D1', 28.33, 22.5, 30, 55 ), $bereich( 'D2', 61.67, 22.5, 30.83, 55 ) ),
				),
			),
			'faecher'    => $faecher,
		);
	}
}
