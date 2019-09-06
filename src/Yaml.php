<?php
namespace dirtsimple\Postmark;

use Symfony\Component\Yaml\Yaml as Yaml12;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Exception\DumpException;

class Yaml {
	const
		ROOT_FLAGS = Yaml12::DUMP_OBJECT_AS_MAP,
		DUMP_FLAGS = Yaml12::DUMP_OBJECT_AS_MAP |
		             Yaml12::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

	static function parse($data, $filename=null) {
		return Yaml12::parse($data);
	}

	static function dump($data) {
		return static::_dump($data);
	}

	static function parseFile($filename) {
		return static::parse( file_get_contents($filename), $filename );
	}

	# Like Symfony's dump, but w/fixes for block chomping on multiline literals,
	# and nested data structures are only inlined if they can fit on the current
	# line without wrapping, and have no non-empty containers as children.
	#
	# The overall goal is to optimize for revision control, such that diffs
	# don't contain extraneous changes, and line breaks follow the source data
    # (allowing in-line change highlighting where tooling allows).  For this
    # reason, we don't do line folding, even though it would improve readability,
    # because even a small change to such a string could rewrap the rest of the
    # string, polluting the diff with semantically-irrelevant changes.
	#
	protected static function _dump($data, $width=120, $indent='  ', $prefix='', $keylen=null) {
		# See if $data can be rendered as a simple (leaf) value
		switch(true) {
		case is_string($data):
			if (
				( $lines = substr_count($data, "\n") ) && # multi-line
				false === strpos($data, "\r\n") &&        # no lossy CRLFs
				($lines > 1 || strlen($data) > $width - $keylen) # two LFs or too wide
			) {
				$indicator = substr_compare($data, ' ', 0, 1) ? '': strlen($indent);
				if ( substr_compare($data, "\n", -1) ) {
					$data .= "\n";
					$indicator .= '-';  # strip the \n we added
				} else if ( ! substr_compare($data, "\n\n", -2) ) {
					$indicator .= '+';  # keep trailing blank lines
				}
				return "|$indicator\n" . preg_replace('/^/m', "$prefix$indent", $data);
			}
			# ...else intentional fallthrough, since strings are leaves
		case is_scalar($data):	# inline other common leaf cases and fall through
		case empty($data):
		case static::_is_leaf($data):
			if ( isset($keylen) ) {
				# Recursive call: return everything on one line
				return Inline::dump($data, self::DUMP_FLAGS);
			} else {
				# Root call: include prefix and trailing LF
				return $prefix . Inline::dump($data, self::ROOT_FLAGS) . "\n";
			}
		}

		# Not a leaf, it's a non-empty array (or array-like object)
		$out = array();
		$isMap = Inline::isHash($data);
		$room = $width - $keylen;
		$width -= strlen($indent);
		$nested = "$prefix$indent";
		foreach ($data as $k => $v) {
			$k = $isMap ? Inline::dump($k, self::DUMP_FLAGS).': ' : '';
			$v = static::_dump($v, $width, $indent, $nested, strlen($k));
			$out[] = $v = "$k$v";
			$room -= substr_compare($v, "\n", -1) ? strlen($v) + 2 : $room;
		}

		if ( $room >= 4 ) {  # allow for [ ] / { }
			return sprintf( $isMap ? "{ %s }" : "[ %s ]", implode(', ', $out) );
		}

		$out = preg_replace('/([^\n])$/D', "\\1\n", $out);  # add missing LFs
		if ( ! $isMap ) $prefix .= '- ';
		return (isset($keylen) ? "\n" : "") . $prefix . implode($prefix, $out);
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