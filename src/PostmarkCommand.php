<?php
namespace dirtsimple\Postmark;
use WP_CLI;

/**
 * Sync posts or pages from static markdown files
 *
 */
class PostmarkCommand {

	/**
	 * Sync one or more post file(s)
	 *
	 * <file>...
	 * : One or more paths to .md file(s) to sync
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Update the post(s) in the DB even if the file(s) are unchanged
	 *
	 * [--skip-create]
	 * : Don't add GUIDs to post files that lack them; exit with an error instead.
	 *
	 * [--porcelain]
	 * : Output just the ID of the created or updated post
	 */
	function sync( $args, $flags ) {
		try {
			$db = $this->db($flags);
			$this->sync_docs( array_map( array($db, 'doc'), $args ), $flags );
		} catch (Error $e) {
			WP_CLI::error($e->getMessage());
		}
	}

	/**
	 * Sync every .md file under one or more directories
	 *
	 * <dir>...
	 * : One or more paths of directories containing markdown file(s) to sync
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Update the post(s) in the DB even if the file(s) are unchanged
	 *
	 * [--skip-create]
	 * : Don't add GUIDs to post files that lack them; exit with an error instead.
	 *
	 * [--porcelain]
	 * : Output just the IDs of the created or updated posts
	 */
	function tree( $args, $flags ) {
		try {
			$db = $this->db($flags);
			foreach ( $args as $arg )
				$this->sync_docs( $db->docs(trailingslashit($arg) . "*.md"), $flags, $arg );
		} catch (Error $e) {
			WP_CLI::error($e->getMessage());
		}
	}

	/**
	 * Generate a unique ID for use in a markdown file
	 */
	function uuid( $args ) { WP_CLI::line('urn:uuid:' . wp_generate_uuid4()); }


	// -- non-command utility methods --

	protected function db($flags) {
		return new Database(
			! WP_CLI\Utils\get_flag_value($flags, 'force', false),
			! WP_CLI\Utils\get_flag_value($flags, 'skip-create', false)
		);
	}

	protected function result($doc, $res, $porcelain, $already=true) {
		if ( is_wp_error( $res ) )
			WP_CLI::error($res);
		elseif ( $porcelain ) {
			if ( $res !== null ) WP_CLI::line($res);
		}
		elseif ( $already )
			WP_CLI::debug("$doc->filename already synced", "postmark");
		elseif ( $res !== null )
			WP_CLI::success("$doc->filename successfully synced, ID=$res", "postmark");
		else
			WP_CLI::success("$doc->filename successfully synced", "postmark");
	}

	protected function sync_docs($docs, $flags, $dir=null) {
		if ( empty($docs) && isset($dir) ) {
			$dir = Project::realpath($dir);
			WP_CLI::warning("no .md files found in $dir");
		}
		$porcelain = WP_CLI\Utils\get_flag_value($flags, 'porcelain', false);
		foreach ($docs as $doc) {
			WP_CLI::debug("Syncing $doc->filename", "postmark");
			if     ( ! $doc->file_exists() )
				WP_CLI::error("$doc->filename does not exist");
			elseif ( $res = $doc->synced() )
				$this->result($doc, $res,         $porcelain, true);
			else
				$this->result($doc, $doc->sync(), $porcelain, false);
		}
	}

}

