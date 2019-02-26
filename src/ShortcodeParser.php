<?php

namespace dirtsimple\Postmark;

use League\CommonMark\Block\Element\HtmlBlock;
use League\CommonMark\Block\Parser\AbstractBlockParser;
use League\CommonMark\ContextInterface;
use League\CommonMark\Cursor;

class ShortcodeParser extends AbstractBlockParser {

	function parse(ContextInterface $context, Cursor $cursor) {
		if ($cursor->getNextNonSpaceCharacter() !== '[') return false;
		$s = $cursor->saveState();
		$cursor->advanceToNextNonSpaceOrTab();
		$line = $cursor->getRemainder();
		if ( preg_match( '/^(\[[^\[\]]+\]|\[\[[^\[\]]+\]\])+$/', $line ) ) {
			// Add a dummy HTML block with just the shortcode
			$context->addBlock($h = new HtmlBlock(HtmlBlock::TYPE_7_MISC_ELEMENT));
			$h->addLine($line);

			// Close the HTML block and reset the parser to the current container
			$h->finalize($context, $context->getLineNumber());
			$context->setContainer($h->parent());

			// Mark the text consumed and proceed
			$cursor->advanceToEnd();
			return true;
		}
		$cursor->restoreState($s);
		return false;
	}

}
