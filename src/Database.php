<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Imposer;
use dirtsimple\imposer\PostModel;
use dirtsimple\imposer\Promise;

class Database {

	protected $cache, $allowCreate;

	function __construct($cache=true, $allowCreate=true) {
		# ensure Imposer and all hooks/tasks are initialized first
		Imposer::instance();
		$this->reindex($cache);
		$this->allowCreate = $allowCreate;
		$this->post_types = array_fill_keys(get_post_types(), 1);
	}

	static function legacy_filter($types) {
		return apply_filters('postmark_excluded_types', $types);
	}

	static function lookup_by_option_guid($guid) {
		return Option::postFor($guid) ?: null;
	}

	protected function reindex($cache) {
		global $wpdb;
		$filter = PostModel::posttype_exclusion_filter();
		if ( $cache ) $this->cache = array_flip(
			get_option( 'postmark_option_cache' ) ?: array()
		) + $this->_index(
			"SELECT post_id, meta_value FROM $wpdb->postmeta, $wpdb->posts
			 WHERE meta_key='_postmark_cache' AND post_id=ID AND $filter"
		); else $this->cache = array();
	}

	protected function _index($query) {
		global $wpdb; return array_column( $wpdb->get_results($query, ARRAY_N), 0, 1 );
	}

	function sync($filename, $callback) {
		$filename = Project::realpath($filename);
		if ( isset($this->cache[$key = Project::cache_key($filename)]) ) {
			return $callback(false, $ret = $this->cache[$key]);
		}
		$res = Promise::interpret( Project::doc($filename)->sync($this) );
		return Promise::value($res)->then( function($res) use ($callback) {
			return $callback(true, $res);
		});
	}

	function cachedID($doc) {
		if ( isset($this->cache[$key = $doc->key()]) ) return $this->cache[$key];
	}

	function cache($doc, $res) {
		if ($res) {
			$this->cache[$doc->key()] = $res;
			if ($keypath = Option::parseIdURL($doc->ID)) Option::patch($keypath, $res);  # XXX is_wp_error?
		}
	}

	function parent_id($doc) {
		if ( ! $doc = Project::parent_doc($doc->filename) ) return null;  # root, no parent
		if ( $id = $this->cachedID($doc) ) return $id;  # cached ID, we're done

		$guid = $this->guidForDoc($doc);
		if ( is_wp_error($guid) ) return $guid;

		$id = Imposer::resource('@wp-post')->lookup($guid, 'guid');
		return $id ?: $doc->sync($this);
	}

	function guidForDoc($doc) {
		return (
			$doc->ID           ? $doc->ID : (
			$this->allowCreate ? $this->newID($doc) : (
			$doc->filenameError('missing_guid', __( 'Missing or Empty `ID:` field in %s', 'postmark'))))
		);
	}

	protected function newID($doc) {
		$guid = 'urn:uuid:' . wp_generate_uuid4();
		return Project::injectGUID($doc->filename, $guid) ? ($doc->ID = $guid) : $doc->filenameError('save_failed', __( 'Could not save new ID to %s', 'postmark'));
	}

	static function export($post_spec, $dir='') {
		$guid = null;
		if ( ! is_numeric($id = $post_spec) ) {
			if ( $id = Imposer::resource('@wp-post')->lookup($post_spec, 'guid') ) {
				$guid = $post_spec;
			} else {
				$id = Imposer::resource('@wp-post')->lookup($post_spec);
				if ( ! $id ) return false;
			}
		}
		$post = get_post($id);
		if (! $post ) return false;
		if ( is_wp_error($post) ) return $post;

		$ef = new ExportFile($post, $guid);
		return $ef->exportTo($dir); # XXX ?: new WP_Error ...
	}

}
