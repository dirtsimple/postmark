<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Imposer;
use dirtsimple\imposer\Pool;
use dirtsimple\imposer\PostModel;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\WatchedPromise;

class Database {

	protected $cache, $allowCreate;

	function __construct($cache=true, $allowCreate=true) {
		# ensure Imposer and all hooks/tasks are initialized first
		Imposer::instance();
		$this->reindex($cache);
		$this->allowCreate = $allowCreate;
		$this->post_types = array_fill_keys(get_post_types(), 1);

		$this->docs = new Pool(function($filename) { return Project::doc($filename, false); });
		$this->results = new Pool(function($filename, $pool) {
			$doc = $this->docs[$filename];

			# Valid ID?
			if ( is_wp_error( $guid = $this->guidForDoc($doc) ) ) {
				return $guid;
			}

			$handler = Option::parseValueURL($guid) ? 'Option' : 'PostImporter';
			$handler = "dirtsimple\\Postmark\\$handler::sync_doc";
			$handler = apply_filters('postmark_sync_handler', $handler, $doc);

			$ref = $pool[$filename] = new WatchedPromise;
			$ref->call(function() use ($handler, $doc) {
				$this->cache[$doc->etag()] = yield $handler($doc, $this);
			});
			return $ref;
		});
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
		if ( $cache ) $this->cache = apply_filters(
			'postmark_etag_cache', array_flip(
				get_option( 'postmark_option_cache', array() )
			) + $this->_index(
				"SELECT post_id, meta_value FROM $wpdb->postmeta, $wpdb->posts
				 WHERE meta_key='_postmark_cache' AND post_id=ID AND $filter"
			)
		); else $this->cache = array();
	}

	protected function _index($query) {
		global $wpdb; return array_column( $wpdb->get_results($query, ARRAY_N), 0, 1 );
	}

	function sync($filename, $callback=null) {
		# Default callback just passes result through
		$callback = $callback ?: function($already, $res) { return $res; };

		$doc = $this->docs[$filename];
		if ( isset($this->cache[$etag = $doc->etag()]) ) {
			return $callback(false, $ret = $this->cache[$etag]);
		}

		$res = $this->results[$doc->filename];
		$ret = Promise::now($res, $sentinel = (object) array());
		if ( $ret !== $sentinel ) return $callback(true, $ret);
		return $res->then( function($ret) use ($callback) {
			return $callback(true, $ret);
		});
	}

	protected function guidForDoc($doc) {
		return (
			$doc->ID           ? $doc->ID : (
			$this->allowCreate ? $this->newID($doc) : (
			$doc->filenameError('missing_guid', __( 'Missing or Empty `ID:` field in %s', 'postmark'))))
		);
	}

	protected function newID($doc) {
		$guid = 'urn:uuid:' . wp_generate_uuid4();
		return Project::injectGUID($doc->filename, $guid) ? $doc->load(true)->ID : $doc->filenameError('save_failed', __( 'Could not save new ID to %s', 'postmark'));
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
