<?php
namespace dirtsimple\Postmark;

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'postmark', PostmarkCommand::class );
}

