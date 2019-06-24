<?php
namespace dirtsimple\Postmark;

use WP_Error;

class Document extends MarkdownFile {

	protected $loaded=false, $_cache_key, $db, $_kind=null, $slug, $project, $filename;

	static function fetch(\ArrayAccess $cache, Database $db, $filename) {
		# Avoid repeated calls to realpath
		if ( $cache->offsetExists($filename) ) return $cache[$filename];
		$realpath = Project::realpath($filename);
		if ( $cache->offsetExists($realpath) ) return $cache[$realpath];
		return $cache[$filename] = $cache[$realpath] = new Document($realpath, $db);
	}

	function __construct($filename, Database $db) {
		$this->filename = $filename;
		$this->db = $db;
	}

	function load($reload=false) {
		if ( ! $reload && $this->loaded ) return $this;

		$this->loadFile( $this->filename );
		$this->loaded = true;

		# Compute slug
		$parts = explode( '.', Project::basename($this->filename) );
		array_pop($parts);   # remove .md

		if ( ! $this->has('Prototype') && count($parts) > 1 ) {
			# move "extension" to prototype
			$this->Prototype = array_pop($parts);
		}

		$this->slug = implode('.', $parts);
		if ( $this->slug === 'index' ) {
			$slug = dirname($this->filename);
			$this->slug = ( $slug == dirname($slug) ) ? null : Project::basename($slug, '.md');
		}

		# Apply prototype
		if ( ! empty($proto = $this->Prototype) ) {
			Project::prototype($this->filename, $proto)->apply_to($this);
		}

		do_action('postmark_load', $this);   # XXX deprecated

		# Determine resource _kind
		if ( ! $this->has('Resource-Kind') && $this->has('ID')) {
			if ( Option::parseValueURL($this->ID) ) $this['Resource-Kind'] = 'wp-option-html';
		}

		$_kind = $this->setdefault('Resource-Kind', 'wp-post');
		$this->db->kind($_kind)->getImporter($this);  # validate _kind is importable

		do_action("postmark load $_kind", $this);

		$this->_kind = $_kind;
		$this->_cache_key = Project::cache_key($this->filename) . ":" . md5($this->dump());

		return $this;
	}

	function __get($key) {       $this->load(); return parent::__get($key); }
	function __set($key, $val) { $this->load(); parent::__set($key, $val); }
	function __isset($key) {     $this->load(); return parent::__isset($key); }
	function __unset($key) {     $this->load(); parent::__unset($key); }

	function etag() { return $this->load()->_cache_key; }
	function kind() { return $this->load()->_kind; }
	function slug() { return $this->load()->slug; }

	function filename() { return $this->filename; }

	function sync($callback=null) {
		return $this->db->sync($this->filename, $callback);
	}

	function filenameError($code, $message, ...$args) {
		return new WP_Error($code, sprintf($message, $this->filename, ...$args));
	}

	function parent() {
		$filename = Project::parent_of($this->filename);
		return isset($filename) ? $this->db->doc($filename) : null;
	}
}
