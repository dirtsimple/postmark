<?php
namespace dirtsimple\Postmark;
use WP_CLI;
use dirtsimple\imposer\Imposer;

/**
 * Sync posts or pages from static markdown files
 *
 */
class PostmarkCommand {

	/**
	 * Export one or more post(s)
	 *
	 * [<post-spec>...]
	 * : One or more post IDs, GUIDs, URLs, or paths
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<output-dir>]
	 * : The directory the post(s) will be exported to. (Default is '.')
	 *
	 * [--porcelain]
	 * : Output just the filenames of the exported posts; an empty line means the post-spec was not found.
	 *
	 * [--allow-none]
	 * : Allow the list of post-specs to be empty (for scripting purposes).  Defaults to true if --porcelain is used.
	 *
	 */
	function export( $args, $options ) {

		$porcelain  = WP_CLI\Utils\get_flag_value($options, 'porcelain',   false);
		$allow_none = WP_CLI\Utils\get_flag_value($options, 'allow-none', $porcelain);

		$dir = isset($options['dir']) ? WP_CLI\Utils\trailingslashit($options['dir']) : '';

		if ( ! $args && ! $allow_none ) WP_CLI::error("No posts specified");

		foreach ( $args as $post_spec ) {
			$res = Database::export($post_spec, $dir);
			switch (true) {
				case is_wp_error( $res ):
					WP_CLI::error($res);
					break;
				case (bool) $porcelain:
					WP_CLI::line($res ?: "");
					break;
				case (bool) $res:
					WP_CLI::success("Exported post $post_spec to $res", "postmark");
					break;
				default:
					WP_CLI::warning("No post found for $post_spec");
			}
		}
	}

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
	 * : Output just the ID of the created or updated post (or the GUIDs of option values synced)
	 */
	function sync( $args, $flags ) {
		Imposer::task( $this->taskName("wp postmark sync", $args, $flags) )
			->produces('@wp-posts')
			->steps( function() use ($args, $flags) {
				try {
					yield $this->sync_docs( $args, $flags );
				} catch (Error $e) {
					WP_CLI::error($e->getMessage());
				}
			});
		Imposer::run();
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
	 * : Output just the IDs of the created or updated posts (or the GUIDs of option values synced)
	 */
	function tree( $args, $flags ) {
		Imposer::task( $this->taskName("wp postmark tree", $args, $flags) )
			->produces('@wp-posts')
			->steps( function() use ($args, $flags) {
				try {
					$docs = array();
					foreach ( $args as $arg ) {
						$files = Project::find(trailingslashit($arg) . "*.md");
						if ( empty($files) ) {
							WP_CLI::warning("no .md files found in ", Project::realpath($arg));
						}
						$docs = array_merge($docs, $files);
					}
					yield $this->sync_docs($docs, $flags, false);
				} catch (Error $e) {
					WP_CLI::error($e->getMessage());
				}
			});
		Imposer::run();
	}

	/**
	 * Generate unique ID(s) for use in markdown file(s)
	 *
	 * [<file>...]
	 * : Write an ID: to listed file(s) that lack one.  If no files given, write a UUID to the console
	 */
	function uuid( $args ) {
		if ( ! $args) {
			WP_CLI::line('urn:uuid:' . wp_generate_uuid4());
			return;
		}
		foreach ( $args as $filename ) {
			if ( ! file_exists($filename) ) WP_CLI::error("$filename does not exist");
			$md = MarkdownFile::fromFile($filename);
			if ( $guid = $md->get('ID') ) {
				WP_CLI::success("$filename already has an ID: of $guid");
				continue;
			}
			$md->ID = $guid = 'urn:uuid:' . wp_generate_uuid4();
			if ( $md->saveAs($filename) )
				WP_CLI::success("$filename ID: set to $guid");
			else WP_CLI::error("Could not save new ID to $filename");
		}
	}


	// -- non-command utility methods --

	protected function taskName($cmd, $args, $flags) {
		return implode(
			' ', array_filter(
				array(
					$cmd,
					$args ? '"' . implode('" "', $args) . '"' : '',
					($flags ? '--' : '') . implode(' --', array_keys($flags))
				)
			)
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

	protected function sync_docs($docs, $flags, $explicit=true) {
		$porcelain = WP_CLI\Utils\get_flag_value($flags, 'porcelain', false);

		$db = new Database(
			! WP_CLI\Utils\get_flag_value($flags, 'force', false),
			! WP_CLI\Utils\get_flag_value($flags, 'skip-create', false)
		);

		foreach ($docs as $filename) {
			$doc = Project::doc($filename);
			WP_CLI::debug("Syncing $doc->filename", "postmark");
			if ( ! $doc->file_exists() ) {
				WP_CLI::error("$doc->filename is empty or does not exist", $explicit);
			} elseif ( $res = $db->cachedID($doc) )
				$this->result($doc, $res,      $porcelain, true);
			else {
				$res = (yield( $doc->sync($db) ));
				if ( ! $explicit && is_wp_error($res) && $res->get_error_code() == 'missing_guid' )
					WP_CLI::error($res->get_error_message(), false);
				else $this->result($doc, $res, $porcelain, false);
			}
		}
	}

}

