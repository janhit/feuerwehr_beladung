<?php
/**
 * Lernseite für Mitglieder: Shortcode [feuerwehr_beladung].
 *
 * Die Seite selbst enthält keine Beladungsdaten. Die kleine JavaScript-
 * Anwendung holt sie nach dem Laden über die REST-Schnittstelle – nur mit
 * Anmeldung. Abgemeldete sehen einen Hinweis mit Anmeldeknopf.
 *
 * @package FeuerwehrBeladung
 */

namespace FWB;

defined( 'ABSPATH' ) || exit;

final class Lernseite {

	public const SHORTCODE = 'feuerwehr_beladung';

	private static bool $eingebunden = false;

	public static function haken(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'ausgeben' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'vorab_einbinden' ) );
		add_action( 'template_redirect', array( self::class, 'nicht_zwischenspeichern' ) );
	}

	private static function seite_hat_shortcode(): bool {
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/** Seiten mit Lernseite nie in einem Seiten-Cache landen lassen. */
	public static function nicht_zwischenspeichern(): void {
		if ( is_singular() && self::seite_hat_shortcode() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Wird von gängigen Cache-Plugins beachtet.
			}
			nocache_headers();
		}
	}

	public static function vorab_einbinden(): void {
		if ( is_singular() && self::seite_hat_shortcode() && Plugin::darf_lernen() ) {
			self::einbinden();
		}
	}

	private static function einbinden(): void {
		if ( self::$eingebunden ) {
			return;
		}
		self::$eingebunden = true;
		wp_enqueue_style( 'fwb-lernen', FWB_URL . 'assets/css/lernen.css', array(), Plugin::datei_version( 'assets/css/lernen.css' ) );
		// Ohne Import-Map: app.js lädt logik.js selbst (mit derselben Versionsnummer).
		wp_enqueue_script_module( 'fwb-lernen', FWB_URL . 'assets/js/lernen/app.js', array(), Plugin::datei_version( 'assets/js/lernen/app.js' ) );
	}

	public static function ausgeben( $attribute = array() ): string {
		if ( ! Plugin::darf_lernen() ) {
			$adresse = wp_login_url( (string) get_permalink() );
			return '<div class="fwb-lernen-anmelden" style="padding:1em;border:1px solid #ccc;border-radius:6px">'
				. '<p><strong>Beladung lernen</strong></p>'
				. '<p>Diese Seite ist nur für angemeldete Mitglieder.</p>'
				. '<p><a class="wp-element-button button" href="' . esc_url( $adresse ) . '">Anmelden</a></p></div>';
		}
		self::einbinden();
		$konfig = array(
			'rest'    => esc_url_raw( rest_url( Rest::NAMENSRAUM . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'version' => Plugin::datei_version( 'assets/js/lernen/logik.js' ),
		);
		return '<div class="fwb-lernen" data-konfig="' . esc_attr( (string) wp_json_encode( $konfig ) ) . '">'
			. '<p class="fwb-l-laedt">Fahrzeuge werden geladen …</p>'
			. '<noscript><p>Für die Lernseite muss JavaScript eingeschaltet sein.</p></noscript>'
			. '</div>';
	}
}
