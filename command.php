<?php
namespace dirtsimple\Postmark;

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'postmark', PostmarkCommand::class );
	\WP_CLI::add_hook( 'after_wp_load', function() {
		add_action('imposer_tasks', function(){
			Imposer::resource('@wp-post')->addLookup(
				array(Database::class, 'lookup_by_option_guid'), 'guid'
			);
		});
		add_filter('imposer_nonguid_post_types', array(Database::class, 'legacy_filter'), 0, 1);
	});
}

