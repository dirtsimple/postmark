<?php
namespace dirtsimple\Postmark;

use Mustangostang\Spyc;
use Symfony\Component\Yaml\Yaml as Yaml12;


class Yaml {

	static function parse($data, $filename=null) {
		return Yaml12::parse($data);
	}

	static function dump($data) {
		return Yaml12::dump(
			$data, 4, 2, Yaml12::DUMP_OBJECT_AS_MAP | Yaml12::DUMP_MULTI_LINE_LITERAL_BLOCK
		);
	}

	static function parseFile($filename) {
		return static::parse( file_get_contents($filename), $filename );
	}
}