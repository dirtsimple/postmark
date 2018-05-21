<?php
namespace dsi\Postmark;
use Mustangostang\Spyc;

class MarkdownFile {
	/* A MarkdownFile is a combination of front matter and body */

	public $meta=array(), $body='';

	function parse($text) {
		$meta = '';
		$body = $text;
		if ( preg_match("{^(?:---)[\r\n]+(.*?)[\r\n]+(?:---)[\r\n]+(.*)$}s", $text, $m) === 1) {
			$meta = $m[1]; $body = $m[2];
		}
		$this->meta = ! empty(trim($meta)) ? Spyc::YAMLLoadString(trim($meta)) : array();
		$this->body = $body;
		return $this;
	}

	function loadFile($file) { return $this->parse(file_get_contents($file)); }

	function dump($filename=null) {
		$data = sprintf("%s---\n%s", Spyc::YAMLDump( $this->meta, 2, 0 ), $this->body);
		return isset($filename) ? file_put_contents($filename, $data, LOCK_EX) : $data;
	}

	function saveAs($filename) {
		if ( copy($filename, "$filename.bak") ) {
			$r1 = $this->dump($filename); $r2 = unlink("$filename.bak");
			return $r1 && $r2;
		}
	}

	function meta($key=null, $default=null) {
		if ($key) {
			if ( array_key_exists($key, $this->meta) ) return $this->meta[$key];
			else return $default;
		} else return $this->meta;
	}

	function __get($key) { return $this->meta($key); }
	function __set($key, $val) { $this->meta[$key]=$val; }
	function __isset($key) { return isset($this->meta[$key]); }
}
