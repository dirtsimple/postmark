<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Imposer;
use dirtsimple\imposer\PostModel;

class Database {

	protected $cache, $docs=array(), $allowCreate, $exclude_types, $post_types;

	function __construct($cache=true, $allowCreate=true) {
		add_filter('imposer_nonguid_post_types', array(self::class, 'legacy_filter'));
		Imposer::resource('@wp-post')->addLookup(
			array(self::class, 'lookup_by_option_guid'), 'guid'
		);
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

	function reindex($cache) {
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

	function doc($file, $is_tmpl=false) {
		$file = Project::realpath($file);
		return isset($this->docs[$file]) ? $this->docs[$file] : $this->docs[$file] = new Document($this, $file, $is_tmpl);
	}

	function docs($pat) { return array_map(array($this, 'doc'), Project::find($pat)); }

	function cachedID($doc) {
		if ( isset($this->cache[$key = $doc->key()]) ) return $this->cache[$key];
	}

	function postForGUID($guid) {
		return is_wp_error($guid) ? $guid : Imposer::resource('@wp-post')->lookup($guid, 'guid');
	}

	function cache($doc, $res) {
		if ($res) {
			$this->cache[$doc->key()] = $this->by_uuid[$doc->ID] = $res;
			if ($keypath = Option::parseIdURL($doc->ID)) Option::patch($keypath, $res);  # XXX is_wp_error?
		}
	}

	function postForDoc($doc) {
		return $this->postForGUID(
			$doc->ID           ? $doc->ID : (
			$this->allowCreate ? $this->newID($doc) : (
			$doc->filenameError('missing_guid', __( 'Missing or Empty `ID:` field in %s', 'postmark'))))
		);
	}

	function newID($doc) {
		$md = MarkdownFile::fromFile($file = $doc->filename);
		$md->ID = $guid = 'urn:uuid:' . wp_generate_uuid4();
		return $md->saveAs($file) ? ($doc->ID = $guid) : $doc->filenameError('save_failed', __( 'Could not save new ID to %s', 'postmark'));
	}

	function postTypeOk($post_type) {
		return isset($this->post_types[$post_type]) && !isset($this->exclude_types[$post_type]);
	}

	function export($post_spec, $dir='') {
		$guid = null;
		if ( ! is_numeric($id = $post_spec) ) {
			if ( $id = $this->postForGUID($post_spec) ) {
				$guid = $post_spec;
			} else {
				$id = url_to_postid($post_spec);
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
