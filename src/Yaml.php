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
	protected static function _dump($data, $flags, $tabsize=2, $width=120, $indent=0, $keylen=0) {
		$prefix = str_repeat(' ', $indent);

		if ( static::_is_leaf($data) ) {
			if ($keylen) {
				# Recursive call: return everything on one line
				return Inline::dump($data, $flags);
			} else {
				# Root call: include prefix and trailing LF
				return $prefix . Inline::dump($data, $flags & ~Yaml12::DUMP_EMPTY_ARRAY_AS_SEQUENCE) . "\n";
			}
		}

		$out = array('');
		$isMap = Inline::isHash($data);
		$can_inline = true;
		$inline_room = $width - $keylen - 4;  # allow for [ ] / { }
		$memo = array();

		foreach ($data as $k => $v) {
			$can_inline = ( $inline_room > 0 ) && static::_is_leaf($v);
			if ( ! $can_inline ) break;
			$val = Inline::dump($v, $flags);
			$val = $memo[$k] = $isMap ? Inline::dump($k) . ": $val" : $val;
			$inline_room -= strlen($val) + 2;
		}

		if ( $can_inline ) {
			return sprintf( $isMap ? "{ %s }" : "[ %s ]", implode(', ', $memo) );
		}

		foreach ($data as $k => $v) {
			if ( is_string($v) && strpos($v, "\n") !== false ) {
				# Could this key's value be rendered as a multi-line literal?
				$n = count( $lines = explode("\n", $v) );
				if ( $n > 1 && false === strpos($v, "\r\n") ) {
					$indicator = (' ' === substr($v, 0, 1)) ? $tabsize : '';
					if ( $lines[$n -1] !== '' ) {
						$indicator .= '-';  # strip trailing \n
					} else if ( $lines[$n-2] === '' ) {
						$indicator .= '+';  # keep trailing blank lines
					} else array_pop($lines); # drop unneeded blank line
					$k = $isMap ? Inline::dump($k, $flags).':' : '-';
					$out[] = "$k |$indicator\n";
					$pre = str_repeat(' ', $tabsize);
					foreach ( $lines as $line ) $out[] = "$pre$line\n";
					continue;
				}
			}

			# Not a multi-line literal; can we re-use an existing entry?
			if ( array_key_exists($k, $memo) ) {
				$out[] = ( $isMap ? "": "- " ) . $memo[$k] . "\n";
				continue;
			}

			# Not memoized or a multi-line literal; recurse:
			$k = $isMap ? Inline::dump($k, $flags).':' : '-';
			$v = static::_dump($v, $flags, $tabsize, $width, $indent+$tabsize, strlen($k)+1);
			if ( substr($v, -1) !== "\n" ) {
				$out[] = "$k $v\n";
			} else {
				$out[] = "$k\n$v";
			}
		}
		return implode($prefix, $out);
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