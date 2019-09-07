<?php
namespace dirtsimple\Postmark;

use dirtsimple\CleanYaml;
use Symfony\Component\Yaml\Yaml as SYaml;

class Yaml {
	static function parse($data, $filename=null) {
		return SYaml::parse(
			$data, SYaml::PARSE_DATETIME | SYaml::PARSE_EXCEPTION_ON_INVALID_TYPE
		);
	}

	static function dump($data) {
		return CleanYaml::dump($data);
	}

	static function parseFile($filename) {
		return static::parse( file_get_contents($filename), $filename );
	}
}