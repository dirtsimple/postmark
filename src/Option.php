<?php
namespace dirtsimple\Postmark;

use WP_CLI\Entity\RecursiveDataStructureTraverser;
use WP_CLI\Entity\NonExistentKeyException;
use WP_CLI;

class Option {

	static function postFor($guid) {
		if (
			($keypath = static::parseIdURL($guid)) &&
			($id = static::pluck($keypath)) && ($id !== -1)
		) {
			if ( is_numeric($id) && get_post($id) ) return $id;
			WP_CLI::error("$guid value of '$id' is not a valid, current post ID");
		}
		return false;
	}

	static function parseIdURL($url)    { return static::parseURL($url, 'x-option-id'); }
	static function parseValueURL($url) { return static::parseURL($url, 'x-option-value'); }

	protected static function parseURL($url, $scheme=null) {
		if ( substr($url, 0, 4) !== 'urn:' ) return;
		$url = substr($url, 4);
		if ( $scheme && parse_url($url, PHP_URL_SCHEME) !== $scheme ) return;
		$keypath = array_map( 'urldecode', explode( '/', parse_url($url, PHP_URL_PATH) ) );
		$parts = array_keys(parse_url($url)); sort($parts); $parts = implode(',', $parts);
		if ( empty($keypath[0]) || $parts !== 'path,scheme' )
			WP_CLI::error( "Invalid option URL '$url'" );
		return $keypath;
	}

	static function pluck(array $keypath) {
		$optval = get_option( array_shift($keypath) );
		if ( false === $optval ) return false;
		$traverser = new RecursiveDataStructureTraverser($optval);
		try {
			return $traverser->get( static::normalizedKeyPath($keypath, 1) );
		} catch ( NonExistentKeyException $e ) {
			return false;
		}
	}

	static function sanitize_option($option, $value) {
		global $wp_settings_errors;
		$ret = sanitize_option($option, $value);
		foreach ( (array) $wp_settings_errors as $error ) {
			WP_CLI::error($error['setting'] . ": " . $error['message']);
		}
		return $ret;
	}

	static function patch(array $keypath, $value) {
		$option	= array_shift($keypath);
		$old = $current = static::sanitize_option( $option, get_option( $option ) );
		if ( is_object($current) ) $old = clone $current;
		$traverser = new RecursiveDataStructureTraverser($current);
		try {
			$traverser->insert($keypath, $value);
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );	# XXX return a WP_Error?  Fall through?
		}
		$patched = static::sanitize_option( $option, $traverser->value() );
		if ( $patched === $old ) return;
		update_option( $option, $patched ) || WP_CLI::error( "Could not update option '$option'." );
	}

	static function normalizedKeyPath(array $keypath) {
		return array_map( function( $key ) {
			if ( is_numeric( $key ) && ( $key === (string) intval( $key ) ) ) {
				return (int) $key;
			}
			return $key;
		}, $keypath);
	}
}
