<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Pool;

class Kind {
	protected $importer, $etag_method='', $etag_arg, $etag_autosave;

	function setImporter($cb) {
		$this->importer = $cb;
		return $this;
	}

	function setEtagQuery($query) {
		$this->etag_method = '_etag_query';
		$this->etag_arg = $query;
		return $this;
	}

	function setEtagCallback($cb) {
		$this->etag_method = '_etag_callback';
		$this->etag_arg = $query;
		return $this;
	}

	function setEtagOption($option, $autosave=true) {
		$this->etag_method = '_etag_option';
		$this->etag_arg = $option;
		$this->etag_autosave = $autosave;
		return $this;
	}
}

class KindImpl extends Kind {

	protected $name;

	function __construct($name, $conf) {
		$this->name          = $name;
		$this->importer      = $conf->importer;
		$this->etag_method   = $conf->etag_method;
		$this->etag_arg      = $conf->etag_arg;
		$this->etag_autosave = $conf->etag_autosave;
	}

	function getImporter($doc) {
		$handler = $this->importer;
		if ( empty($handler) ) throw new Error(
			__('%s: No import handler defined for resource kind "%s"', 'postmark'), $doc->filename, $this->name
		);
		if ( ! is_callable($handler) ) throw new Error(
			__('%s: Invalid import handler %s defined for resource kind "%s"', 'postmark'),
			$doc->filename, json_dump($handler), $this->name
		);
		return $handler;
	}

	function import($doc) {
		$handler = $this->getImporter($doc);

		$id = yield $handler($doc);
		if ( is_wp_error($id) ) return;

		if ( $this->etag_method === '_etag_option' && $this->etag_autosave ) {
			Option::patch( array($this->etag_arg, $id), $doc->etag(), 'no' );
		}

		if ( $this->name !== 'wp-post' && substr($id, 0, 1) !== '@' ) yield "@$this->name:id:$id";
	}

	function etags() {
		$etags = array();
		if ( $method = $this->etag_method ) {
			$etags = $this->$method($this->etag_arg);
		}
		if ( $this->name !== 'wp-post' ) {
			$name = $this->name;
			foreach ( $etags as $k => &$v ) if ( substr($v, 0, 1) !== '@' ) $v = "@$name:id:$v";
		}
		return $etags;
	}

	protected function _etag_option($opt) {
		return array_flip( get_option($opt, array()) );
	}

	protected function _etag_query($query) {
		global $wpdb;
		return array_column( $wpdb->get_results($query, ARRAY_N), 0, 1 );
	}

	protected function _etag_callback($cb) {
		return array_flip($cb);
	}
}

