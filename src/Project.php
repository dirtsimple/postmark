<?php
namespace dsi\Postmark;

class Project {

	protected $root;

	function __construct($root) {
		$this->root = $root;
	}

	static function root($file) {
		static $roots = array();
		return $roots[$dir = dirname($file)] = (
			isset($roots[$dir])        ? $roots[$dir]            : (
			( $dir == $file )          ? new static($dir)          : (
			static::is_project($dir)   ? new static(dirname($dir)) : (
			static::__root($dir))))
		);
	}

	static function cache_key($file) {
		$root = static::root($file);
		return substr($file, strlen($root->root)+1) . ':' . filesize($file) . ':' . filemtime($file);
	}

	static function slug($filename) {
		$slug = static::basename($filename, '.md');
		if ( $slug == 'index' ) {
			$slug = dirname($filename);
			if ( $slug == dirname($slug) ) return null;  # at root
			$slug = static::basename($slug, '.md');
		}
		return $slug;
	}

	static function find($pat, $f=0) {
		$files = glob($pat, $f);
		$dir = dirname($pat);
		$dir = $dir == '.' ? '' : \trailingslashit($dir);
		foreach ( glob("$dir*", GLOB_ONLYDIR|GLOB_NOSORT) as $dir ) {
			$files = array_merge( $files, static::find($dir . '/'. basename($pat), $f) );
		}
		return $files;
	}

	static function parent_doc($db, $filename) {
		$dir = dirname($filename);
		if ( static::basename($filename) == 'index.md' ) {
			if ( static::is_project($dir) ) return null;
			$dir = dirname($dir);
		}
		return $db->doc($dir == '.' ? 'index.md' : "$dir/index.md");
	}

	static function realpath($path) {
		# Handle not-existing paths
		return (
			( false === ($p = \realpath($path)) )
			? trailingslashit(realpath(dirname($path))) . basename($path)
			: $p
		);
	}

	protected static function is_project($dir) {
		return (
			file_exists("$dir/.postmark") ||
			file_exists("$dir/.git") ||
			file_exists("$dir/.hg") ||
			file_exists("$dir/.svn")
		);
	}

	protected static function basename( $path, $suffix = '' ) {
		# locale-independent basename
		return urldecode( \basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
	}

}
