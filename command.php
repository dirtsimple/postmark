<?php
namespace dirtsimple\Postmark;
use Symfony\Component\Yaml\Yaml;

use dirtsimple\imposer\Imposer;

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'postmark', PostmarkCommand::class );
	\WP_CLI::add_hook( 'after_wp_load', function() {
		add_action('postmark_resource_kinds', function($kinds) {
			PostImporter::register_kind( $kinds['wp-post'] );
			Option::register_kind( $kinds['wp-option-html'] );
		}, 0);
		add_action('imposer_tasks', function(){
			Imposer::resource('@wp-post')->addLookup(
				array(Database::class, 'lookup_by_option_guid'), 'guid'
			);
		});
		add_filter('imposer_nonguid_post_types', array(Database::class, 'legacy_filter'), 0, 1);
	});

	# Make sure our 3.4+ Yaml loads before any WP plugins (e.g. cloudflare) can vendor it :(
	# We do this by parsing and dumping something that requires escaping, which should
	# force the parser, dumper, escaper, unescaper, and inline classes to all be loaded.
	Yaml::parse(Yaml::dump(array('x'=>array('xyz"22'))));
}

