<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Pool;
use dirtsimple\imposer\Imposer;
use WP_CLI;

class Kind {
	protected $importer, $exporter, $etag_method='', $etag_arg, $etag_autosave;

	function setImporter($cb) {
		$this->importer = $cb;
		return $this;
	}

	function setExporter($cb) {
		$this->exporter = $cb;
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
		$this->exporter      = $conf->exporter;
		$this->importer      = $conf->importer;
		$this->etag_method   = $conf->etag_method;
		$this->etag_arg      = $conf->etag_arg;
		$this->etag_autosave = $conf->etag_autosave;
	}

	function getImporter($doc) {
		$handler = $this->importer;
		if ( empty($handler) ) throw new Error(
			__('%s: No import handler defined for resource kind "%s"', 'postmark'), $doc->filename(), $this->name
		);
		if ( ! is_callable($handler) ) throw new Error(
			__('%s: Invalid import handler %s defined for resource kind "%s"', 'postmark'),
			$doc->filename(), json_dump($handler), $this->name
		);
		return $handler;
	}

	function getExporter($filename=null) {
		$prefix = empty($filename) ? "" : "$filename: ";
		$handler = $this->exporter;
		if ( empty($handler) ) throw new Error(
			__('%sexport handler defined for resource kind "%s"', 'postmark'), $prefix, $this->name
		);
		if ( ! is_callable($handler) ) throw new Error(
			__('%sInvalid export handler %s defined for resource kind "%s"', 'postmark'),
			$prefix, json_dump($handler), $this->name
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

	function exportMeta($doc) {
		if ( ! $doc->has('ID') ) return $doc->filenameError("document has no ID; cannot save");
		$id = Imposer::resource("@$this->name")->lookup($doc->ID, 'guid');
		if ( ! isset($id) ) return false;

		$handler = $this->getExporter($doc ? $doc->filename() : null);

		$dir = dirname($doc->filename());
		if ( $dir === '.' ) $dir = '';
		$res = $handler($mdf = new MarkdownFile(), $id, $dir, $doc);
		if ( $res === false || is_wp_error($res) ) return $res;

		# Verify that exported fields aren't being shadowed by the original markdown
		$filename = $doc->filename();
		$old = MarkdownFile::fromFile($filename)->meta();
		foreach ( static::__overwrites($old, $mdf->meta()) as $path ) {
			WP_CLI::warning(
				"Exported $path field will be ignored by import unless removed from $filename"
			);
		}
		return $mdf->saveMeta( $fn = $doc->metafile() ) ? $fn : false;  # XXX new WP_Error
	}

	static function __overwrites($old, $new, $path="") {
		foreach ($new as $k => $v) {
			if ( ! array_key_exists($k, $old) ) continue;
			if ( is_array($v) and is_array($old[$k]) ) {
				yield from static::__overwrites($old[$k], $v, "$path$k.");
			} else {
				yield "$path$k";
			}
		}
	}

	function export($id, $dir) {
		$handler = $this->getExporter();
		$res = $handler($mdf = new MarkdownFile(), $id, $dir, null);
		if ( $res === false || is_wp_error($res) ) return $res;

		$slug = ( $res && is_string($res) ) ? $res : $mdf->get('Slug');
		if ( empty($slug) ) throw new Error(
			__('export handler defined for resource kind "%s" did not supply a slug', 'postmark'), $this->name
		);

		$suff = '';
		while (
			file_exists($fn = "$dir$slug$suff.md") &&
			( MarkdownFile::fromFile($fn)->ID != $mdf->ID )
		) $suff--;
		return $mdf->saveAs($fn) ? $fn : false;  # XXX new WP_Error
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

