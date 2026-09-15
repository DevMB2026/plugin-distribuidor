<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puerto a PHP de src/lib/colors.js (catalogo-frontend) — mismo diccionario y
 * misma lógica de "X / Y" bicolor, para que el swatch de color se vea
 * IGUAL en el plugin que en el sitio React, aunque el OptionValue no tenga
 * meta.hex guardado. (Idéntico al de catalogo-api-bridge; se duplica a
 * propósito para que este plugin siga sin depender del otro.)
 */
class Catalogo_Distribuidor_Bridge_Colors {

	private static $hex = array(
		'NEGRO' => '#141414', 'BLANCO' => '#ffffff',
		'GRIS' => '#9ca3af', 'GRIS JASPE' => '#a6a6a6', 'GRIS OXFORD' => '#4b5563',
		'GRIS PERLA' => '#d6d6da', 'GRIS ACERO' => '#6b7280', 'GRIS PLATA' => '#c0c4cc',
		'AZUL' => '#2563eb', 'AZUL MARINO' => '#1d283f', 'AZUL REY' => '#1d4ed8', 'REY' => '#1d4ed8',
		'AZUL CIELO' => '#7dd3fc', 'AZUL CLARO' => '#93c5fd', 'FRANCIA' => '#2f6fdb', 'AZUL FRANCIA' => '#2f6fdb',
		'AMARILLO' => '#facc15', 'AMARILLO NEON' => '#e4ff1a',
		'NARANJA' => '#f97316', 'ANARANJADO' => '#f97316', 'NARANJA NEON' => '#ff7a00',
		'ROJO' => '#dc2626',
		'VERDE' => '#22c55e', 'VERDE BANDERA' => '#15803d', 'VERDE BOTELLA' => '#0b3d2e',
		'VERDE LIMON' => '#84cc16', 'VERDE MANZANA' => '#4ade80', 'VERDE BOSQUE' => '#166534',
		'VERDE NEON' => '#39ff14', 'MILITAR' => '#5b5f2a', 'OLIVO' => '#5b5f2a', 'PISTACHE' => '#b5d66a',
		'MORADO' => '#7c3aed', 'LILA' => '#c4b5fd', 'VIOLETA' => '#8b5cf6',
		'ROSA' => '#f472b6', 'ROSA MEXICANO' => '#e6007e', 'FIUSHA' => '#e11d8f', 'FUCSIA' => '#e11d8f',
		'BUGANBILIA' => '#c026a3', 'BUGANBILIA NEON' => '#e5308f',
		'MENTA' => '#7de3b3', 'TURQUESA' => '#06b6d4', 'AQUA' => '#22d3ee', 'PETROLEO' => '#0e5a6b',
		'VINO' => '#6b1220', 'GUINDA' => '#7a1f2b', 'CAFE' => '#5a3620', 'MARRON' => '#5a3620', 'CHOCOLATE' => '#4a2c1a',
		'BEIGE' => '#e7d3a1', 'ARENA' => '#dcc7a0', 'PAJA' => '#e6d8a8', 'STONE' => '#a8a29e', 'HUESO' => '#efe9dd',
		'ORO' => '#c9a227', 'DORADO' => '#c9a227', 'PLATA' => '#c0c4cc', 'PLATEADO' => '#c0c4cc',
	);

	private static $keywords = array(
		array( '/NEGR/', '#141414' ), array( '/BLANC/', '#ffffff' ), array( '/MARINO/', '#1d283f' ),
		array( '/\bGRIS\b|OXFORD|JASPE/', '#9ca3af' ), array( '/AMARILL/', '#f5d90a' ), array( '/NARANJ|ANARANJ/', '#f97316' ),
		array( '/\bROJO\b|CARMES/', '#dc2626' ), array( '/VERDE|MILITAR|OLIV|PISTACHE/', '#22c55e' ),
		array( '/\bAZUL\b|CIELO|FRANCIA|\bREY\b/', '#2563eb' ), array( '/MORAD|LILA|VIOLET/', '#7c3aed' ),
		array( '/ROSA|FIUSHA|FUCSIA|BUGAN/', '#ec4899' ), array( '/VINO|GUINDA/', '#6b1220' ),
		array( '/BEIGE|ARENA|PAJA|HUESO|STONE/', '#e0cfa0' ), array( '/CAFE|MARRON|CHOCOLAT/', '#5a3620' ),
		array( '/TURQUESA|AQUA|PETROLEO/', '#06b6d4' ), array( '/MENTA/', '#7de3b3' ),
		array( '/ORO|DORAD/', '#c9a227' ), array( '/PLATA|PLATEAD/', '#c0c4cc' ),
	);

	const DEFAULT_HEX = '#cbd5e1';

	public static function hex_for_name( $name ) {
		$n = strtoupper( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
		if ( isset( self::$hex[ $n ] ) ) {
			return self::$hex[ $n ];
		}
		foreach ( self::$keywords as $kw ) {
			if ( preg_match( '~' . trim( $kw[0], '/' ) . '~i', $n ) ) {
				return $kw[1];
			}
		}
		return self::DEFAULT_HEX;
	}

	public static function swatch_background( $name, $meta_hex = null ) {
		$parts = array_filter( array_map( 'trim', explode( '/', (string) $name ) ) );
		if ( count( $parts ) > 1 ) {
			$parts = array_values( $parts );
			return sprintf(
				'linear-gradient(135deg, %s 0 50%%, %s 50%% 100%%)',
				self::hex_for_name( $parts[0] ),
				self::hex_for_name( $parts[1] )
			);
		}
		return $meta_hex ? $meta_hex : self::hex_for_name( $name );
	}
}
