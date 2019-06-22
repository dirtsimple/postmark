<?php
namespace dirtsimple\Postmark;

use WP_Error;

class Document extends MarkdownFile {

	/* Lazy-loading Markdown file that knows how to compute key and get slugs */

	protected $loaded=false, $_cache_key, $db, $_kind=null;
	public $filename;

	function __construct($filename, $db) {
		$this->filename = $filename;
		$this->db = $db;
	}

	function load($reload=false) {
		if ( $reload || ! $this->loaded ) {
			$this->loadFile( $this->filename );
			$this->loaded = true;
			Project::load($this);

			if ( ! $this->has('Resource-Kind') && $this->has('ID')) {
				if ( Option::parseValueURL($this->ID) ) $this['Resource-Kind'] = 'wp-option-html';
			}

			$kind = $this->setdefault('Resource-Kind', 'wp-post');
			$this->db->kind($kind)->getImporter($this);  # validate kind is importable

			do_action("postmark load $kind", $this);

			$this->_kind = $kind;
			$this->_cache_key = Project::cache_key($this->filename) . ":" . md5($this->dump());
		}
		return $this;
	}

	function __get($key) {       $this->load(); return parent::__get($key); }
	function __set($key, $val) { $this->load(); parent::__set($key, $val); }
	function __isset($key) {     $this->load(); return parent::__isset($key); }
	function __unset($key) {     $this->load(); parent::__unset($key); }

	function etag() { return $this->load()->_cache_key; }
	function kind() { return $this->load()->_kind; }

	function sync($callback=null) {
		return $this->db->sync($this->filename, $callback);
	}

	function slug() {
		return Project::slug($this->filename);
	}

	function filenameError($code, $message, ...$args) {
		return new WP_Error($code, sprintf($message, $this->filename, ...$args));
	}

	function parent() {
		$filename = Project::parent_of($this->filename);
		return isset($filename) ? $this->db->doc($filename) : null;
	}
}
