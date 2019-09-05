<?php
namespace dirtsimple\Postmark;

use Mustangostang\Spyc;
use Symfony\Component\Yaml\Yaml as Yaml12;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Exception\DumpException;

class Yaml {

	static function parse($data, $filename=null) {
		return Yaml12::parse($data);
	}

	static function dump($data) {
		return static::_dump(
			$data,
			Yaml12::DUMP_OBJECT_AS_MAP |
			Yaml12::DUMP_MULTI_LINE_LITERAL_BLOCK |
			Yaml12::DUMP_EMPTY_ARRAY_AS_SEQUENCE
		);
	}

	static function parseFile($filename) {
		return static::parse( file_get_contents($filename), $filename );
	}

	# Like Symfony's dump, but w/fixes for block chomping on multiline literals,
	# and nested data structures are only inlined if they can fit on the current
	# line without wrapping, and have no non-empty containers as children
	#
	protected static function _dump($data, $flags, $tabsize=2, $width=120, $indent=0) {
		$prefix = str_repeat(' ', $indent);
		if ( ! is_null($out = static::_inline($data, $flags, $width)) ) {
			return "$prefix$out\n";
		}

		$out = array('');
		$isMap = Inline::isHash($data);

		foreach ($data as $k => $v) {
			$k = $isMap ? Inline::dump($k, $flags).':' : '-';
			if ( is_string($v) ) {
				# Could this key's value be rendered as a multi-line literal?
				$n = count( $lines = explode("\n", $v) );
				if ( $n > 1 && false === strpos($v, "\r\n") ) {
					$indicator = (' ' === substr($v, 0, 1)) ? $tabsize : '';
					if ( $lines[$n -1] !== '' ) {
						$indicator .= '-';  # strip trailing \n
					} else if ( $lines[$n-2] === '' ) {
						$indicator .= '+';  # keep trailing blank lines
					} else array_pop($lines); # drop unneeded blank line
					$out[] = "$k |$indicator\n";
					$pre = str_repeat(' ', $tabsize);
					foreach ( $lines as $line ) $out[] = "$pre$line\n";
					continue;
				}
			}
			if ( ! is_null($t = static::_inline($v, $flags, $width-strlen($k)-1)) ) {
				$out[] = "$k $t\n";
			} else {
				$v = static::_dump($v, $flags, $tabsize, $width-$tabsize, $indent+$tabsize);
				$out[] = "$k\n$v";
			}
		}
		return implode($prefix, $out);
	}

	protected static function _inline($data, $flags, $width) {
		if ( static::_is_leaf($data) ) return Inline::dump($data, $flags);

		$width -= 4;  # allow for [ ] / { }
		$estimate = 0;

		# Abort if any non-leaf children or definitely oversized,
		# without actually dumping them (to avoid duplicate work in _dump)
		foreach ($data as $k => $v) {
			if ( ! static::_is_leaf($v) ) return;
			$estimate += strlen($k) + 2 + ( is_scalar($v) ? strlen($v) : 2 );
			if ( $estimate > $width ) return;
		}

		$isMap = Inline::isHash($data);
		$out = '';
		foreach ($data as $k => $v) {
			if ( $out !== '' ) $out .= ', ';
			if ( $isMap ) $out .= Inline::dump($k, $flags) . ": ";
			$out .= Inline::dump($v, $flags);
			if ( strlen($out) > $width ) return;
		}
		return $isMap ? "{ $out }" : "[ $out ]";
	}

	protected static function _is_leaf($data) {
		switch(true) {
			case empty($data):       return true;
			case is_array($data):    return false;
			case ! is_object($data): return true;
			case $data instanceof \stdClass || $data instanceof ArrayObject:
				return empty( (array) $data );
			case $data instanceof \DateTimeInterface:
				return true;
			case $data instanceof TaggedValue:
				return static::_is_leaf($data->getValue());
			default:
				throw new DumpException("Invalid object for dumping");
		}
	}


}