<?php
namespace dirtsimple\Postmark;

class Error extends \Exception {
	function __construct($message, ...$data) {
		parent::__construct( sprintf($message, ...$data) );
	}
}
