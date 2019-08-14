<?php
namespace dirtsimple\Postmark;
use Twig\Loader;
use Twig\Environment;

class Project {

	protected static $docs=array();

	protected $prefix_len=0, $base, $loader, $pdir, $prototypes=array();

	protected function __construct($base) {
		$prefix = dirname($base);
		if ($prefix !== $base) $this->prefix_len = strlen($prefix);

		$this->base = $base;
		$this->loader = new Loader\ArrayLoader();

		$chain = new Loader\ChainLoader( array($this->loader) );
		foreach( array('_', '.') as $pre ) {
			$dir = "$this->base/{$pre}postmark";
			if ( is_dir($dir) ) {
				$chain->addLoader(
					new Loader\FilesystemLoader("{$pre}postmark", $this->base)
				);
				break;
			}
		}
		$this->pdir = $dir;
		$this->env = new Environment($chain, array('autoescape'=>false));

		$type_files = array();
		foreach ( glob("$dir/*.type.*") as $file ) {
			$name = explode('.type.', static::basename($file));
			$type = array_pop($name);
			$name = implode('.type.', $name);
			$type_files[$name][$type] = $file;
		}

		foreach ( $type_files as $name => $files ) {
			$this->prototypes[$name] = new Prototype($this, $files);
		}
	}

	function prototype($type, $filename='') {
		if ( isset( $this->prototypes[$type] ) ) {
			return $this->prototypes[$type];
		} else {
			$filename = empty($filename) ? '' : "$filename: ";
			throw new Error(
				__('%sNo %s type found at %s', 'postmark'), $filename, $type, "$this->pdir/$type.type.{yml,twig,md}"
			);
		}
	}

	static function injectGUID($file, $guid) {
		list ($head, $tail) = explode("\n", file_get_contents($file), 2);
		if ( ! preg_match("{^(?:---)\r*$}", $head) ) {
			$tail = "---\n$head\n$tail";
			$head = "---";
		}
		return static::writeFile($file, "$head\nID: $guid\n$tail");
	}

	static function writeFile($filename, $text) {
		$bak = "$filename.bak";
		if ( ! file_exists($filename) || copy($filename, $bak) ) {
			$r1 = file_put_contents($filename, $text, LOCK_EX) !== false;
			$r2 = ! file_exists($bak) || unlink($bak);
			return $r1 && $r2;
		}
	}

	function render($doc, $tmpl_name, $template=null) {
		if (!is_null($template)) $this->loader->setTemplate($tmpl_name, $template);
		return $this->env->render($tmpl_name,
			array( 'doc' => $doc, 'body' => $doc->body ) + $doc->meta()
		);
	}

	static function root($file) {
		static $roots = array();
		return $roots[$dir = dirname($file)] = (
			isset($roots[$dir])        ? $roots[$dir]     : (
			( $dir == $file )          ? new static($dir) : (
			static::is_project($dir)   ? new static($dir) : (
			static::root($dir))))
		);
	}

	function has_child($path) {
		return substr($path, 0, $this->prefix_len) === substr($this->base, 0, $this->prefix_len);
	}

	function path_to($path) {
		if ( $this->has_child($path) || $this->has_child($path = static::realpath($path)) ) {
			return substr($path, $this->prefix_len);
		}
	}

	static function find($pat, $f=0) {
		$files = glob($pat, $f);
		$dir = dirname($pat);
		$dir = $dir == '.' ? '' : \trailingslashit($dir);
		foreach ( glob("$dir*", GLOB_ONLYDIR|GLOB_NOSORT) as $dir ) {
			if ( basename($dir) === '_postmark' ) continue;
			$files = array_merge( $files, static::find($dir . '/'. basename($pat), $f) );
		}
		return $files;
	}

	static function parent_of($filename) {
		do {
			$dir = dirname($filename);
			if ( static::basename($filename) == 'index.md' ) {
				if ( static::is_project($dir) ) return null;
				$dir = dirname($dir);
			}
			$filename = $dir == '.' ? 'index.md' : "$dir/index.md";
		} while ( ! file_exists($filename) || ! filesize($filename) );
		return $filename;
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
			file_exists("$dir/_postmark") ||
			file_exists("$dir/.postmark") ||
			file_exists("$dir/.git") ||
			file_exists("$dir/.hg") ||
			file_exists("$dir/.svn")
		);
	}

	static function basename( $path, $suffix = '' ) {
		# locale-independent basename
		return urldecode( \basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
	}

}
