<?php
namespace dirtsimple\Postmark;

use League\CommonMark\Block;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment;
use League\CommonMark\Extension;
use League\CommonMark\Inline;

class Formatter {

	protected static $converter;

	static function format($doc, $field, $value) {
		static $converter = null;
		$converter = $converter ?: static::formatter();
		$markdown = apply_filters('postmark_markdown', $value, $doc, $field);
		$html = $converter->convertToHtml($markdown);
		return apply_filters('postmark_html', $html, $doc, $field);
	}

	protected static function formatter() {
		$cfg = array(
			'renderer' => array(
				'block_separator' => "",
				'inner_separator' => "",
				'line_break' => "",
			),
			'extensions' => array(
				'dirtsimple\Postmark\ShortcodeParser' => null,
			),
		);
		$env = Environment::createCommonMarkEnvironment();
		$cfg = apply_filters('postmark_formatter_config', $cfg, $env);

		static::addExtensions($env, $cfg['extensions']);
		unset( $cfg['extensions'] );
		return new CommonMarkConverter($cfg, $env);
	}

	protected static function addExtensions($env, $exts) {
		foreach ($exts as $ext => $args) {
			if ( false === $args ) continue;
			$extClass = new \ReflectionClass($ext);
			static::addExtension($env, $ext, $extClass->newInstanceArgs((array) $args));
		}
	}

	protected static function addExtension($env, $name, $ext) {
		switch (true) {
		case $ext instanceof Extension\ExtensionInterface                 : $env->addExtension($ext);         break;
		case $ext instanceof Block\Parser\BlockParserInterface            : $env->addBlockParser($ext);       break;
		case $ext instanceof Inline\Parser\InlineParserInterface          : $env->addInlineParser($ext);      break;
		default: throw new Error(__('Unrecognized extension type: %s', 'postmark'), $name);
		}
	}

}
