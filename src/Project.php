<?php
namespace dirtsimple\Postmark;
use Twig\Loader;
use Twig\Environment;
use Mustangostang\Spyc;

class Project {

	protected $root;

	function __construct($root, $base) {
		$this->root = $root;
		$this->base = $base;
		$this->loader = new Loader\ArrayLoader();
		$chain = new Loader\ChainLoader( array($this->loader) );
		if ( is_dir("$this->base/.postmark") ) $chain->addLoader(
			new Loader\FilesystemLoader(".postmark", $this->base)
		);
		$this->env = new Environment($chain, array('autoescape'=>false));
	}

	static function load($doc, $db) {
		if ( !empty($doc->Prototype) ) {
			$root = static::root($doc->filename);
			$typename = $doc->Prototype;
			$found = false;
			$typefile = "$root->base/.postmark/$typename.type";

			if ( file_exists("$typefile.yml") ) {
				$found = true;
				$doc->meta = $doc->meta + Spyc::YAMLLoad("$typefile.yml");
			}
			if ( file_exists("$typefile.md") ) {
				$found = true;
				$type = $db->doc("$typefile.md", true)->load();
				$doc->meta = $doc->meta + $type->meta;
				if ( ! $doc->is_template && !empty(trim($tpl = $type->body)) ) {
					$doc->body = $root->render($doc, "$typename.type.md", $tpl);
				}
			}
			if ( file_exists("$typefile.twig") ) {
				$found = true;
				if ( ! $doc->is_template ) $doc->body = $root->render($doc, "$typename.type.twig");
			}
			if ( ! $found ) {
				throw new Error(
					__('%s: No %s type found at %s', 'postmark'), $doc->filename, $typename, "$typefile.{yml,twig,md}"
				);
			}
		}
		do_action('postmark_load', $doc);
	}

	function render($doc, $tmpl_name, $template=null) {
		if ( preg_match( '"^(/\s*\n)?```twig\n(.*)\n```(\n\s*)?$"is', $template, $m) ) {
			$template = $m[2];
		}
		if (!is_null($template)) $this->loader->setTemplate($tmpl_name, $template);
		return $this->env->render($tmpl_name,
			array( 'doc' => $doc, 'body' => $doc->body ) + $doc->meta
		);
	}

	static function root($file) {
		static $roots = array();
		return $roots[$dir = dirname($file)] = (
			isset($roots[$dir])        ? $roots[$dir]                    : (
			( $dir == $file )          ? new static($dir)                : (
			static::is_project($dir)   ? new static(dirname($dir), $dir) : (
			static::root($dir))))
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
