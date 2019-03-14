<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Bag;
use Mustangostang\Spyc;

class MarkdownFile extends Bag {
	/* A MarkdownFile is a combination of front matter and body */

	public $body='';
	private $meta;  # pseudo-property handled by __get/__set/etc.

	static function fromFile($file) {
		$cls = static::class;
		$inst = new $cls;
		return $inst->loadFile($file);
	}

	function parse($text) {
		$meta = '';
		$body = $text;
		if ( preg_match("{^(?:---)[\r\n]+(.*?)[\r\n]+(?:---)[\r\n]+(.*)$}s", $text, $m) === 1) {
			$meta = $m[1]; $body = $m[2];
		}
		$this->exchangeArray( ! empty(trim($meta)) ? Spyc::YAMLLoadString(trim($meta)) : array() );
		$this->body = $body;
		return $this;
	}

	function unfence($type, $propName='body') {
		$text = $this->{$propName};
		return ( preg_match('_
			^(?:\s*[\r\n]+)?   # Optional leading whitespace ending w/new line
			(~~~+|```+)\s*     # unindented commonmark opening fence, w/optional spaces
			([^`\s]+)          # first word of language tag
			[^`]*?[\r\n]+      # any other words, new line(s)
			(.*?[\r\n]+)       # body content, including trailing newlines
			\1\s*$             # close fence, trailing whitespace, EOF
			_sx', $text, $m) === 1
			&& $type === $m[2]
		) ? $m[3] : $text;
	}

	function loadFile($file) { return $this->parse(file_get_contents($file)); }

	function dump($filename=null) {
		$data = sprintf("%s---\n%s", Spyc::YAMLDump( $this->items(), 2, 0 ), $this->body);
		return isset($filename) ? file_put_contents($filename, $data, LOCK_EX) : $data;
	}

	function saveAs($filename) {
		if ( copy($filename, "$filename.bak") ) {
			$r1 = $this->dump($filename); $r2 = unlink("$filename.bak");
			return $r1 && $r2;
		}
	}

	function meta($key=null, $default=null) {
		return $key ? $this->get($key, $default) : $this->items();
	}

	function inherit(array $other) {
		$meta = json_decode(json_encode($this->items()));
		$meta = \dirtsimple\imposer\array_patch_recursive($other, $meta);
		$this->exchangeArray($meta);
	}

	function __get($key) {
		return ($key === 'meta') ? $this->items() : $this->get($key);
	}
	function __set($key, $val) {
		if ($key === 'meta') $this->exchangeArray($val); else $this[$key] = $val;
	}
	function __isset($key) {
		return ($key === 'meta') ? true : $this->offsetExists($key);
	}
	function __unset($key) {
		if ($key === 'meta') $this->exchangeArray(array()); else parent::__unset($key);
	}
}
