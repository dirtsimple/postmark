<?php
namespace dirtsimple\Postmark;

use WP_Error;

class Document extends MarkdownFile {

	/* Lazy-loading Markdown file that knows how to compute key and get slugs */

	protected $loaded=false, $_cache_key;
	public $filename, $postinfo=null, $is_template;

	function __construct($filename, $is_tmpl=false) {
		$this->filename = $filename;
		$this->is_template = $is_tmpl;
	}

	function load($reload=false) {
		if ( $reload || ! $this->loaded ) {
			$this->loadFile( $this->filename );
			$this->loaded = true;
			Project::load($this);
			$this->_cache_key = Project::cache_key($this->filename) . ":" . md5($this->dump());
		}
		return $this;
	}

	function __get($key) {       $this->load(); return parent::__get($key); }
	function __set($key, $val) { $this->load(); parent::__set($key, $val); }
	function __isset($key) {     $this->load(); return parent::__isset($key); }
	function __unset($key) {     $this->load(); parent::__unset($key); }

	function etag()      { return $this->load()->_cache_key; }

	function slug() {
		return Project::slug($this->filename);
	}

	function filenameError($code, $message, ...$args) {
		return new WP_Error($code, sprintf($message, $this->filename, ...$args));
	}

}
