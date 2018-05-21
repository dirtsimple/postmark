<?php
namespace dsi\Postmark;

class Database {

	protected $cache, $by_guid, $docs=array(), $allowCreate, $exclude_types, $post_types;

	function __construct($cache=true, $allowCreate=true) {
		$this->reindex($cache);
		$this->allowCreate = $allowCreate;
		$this->post_types = array_fill_keys(get_post_types(), 1);
	}

	function reindex($cache) {
		global $wpdb;
		$excludes = apply_filters('postmark_excluded_types', array('revision','edd_payment','shop_order','shop_subscription'));
		$excludes = array_fill_keys($excludes, 1); ksort($excludes); $this->exclude_types = $excludes;
		$filter = 'post_type NOT IN (' . implode(', ', array_fill(0, count($excludes), '%s')) . ')';
		$filter = $wpdb->prepare($filter, array_keys($excludes));
		$this->by_guid = $this->_index("SELECT ID, guid FROM $wpdb->posts WHERE $filter");
		if ( $cache ) $this->cache = $this->_index(
			"SELECT post_id, meta_value FROM $wpdb->postmeta, $wpdb->posts
			 WHERE meta_key='postmark_cache' AND post_id=ID AND $filter"
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

	function cachedPost($doc) {
		if ( isset($this->cache[$key = $doc->key()]) ) return $this->cache[$key];
	}

	function postForGUID($guid) {
		return is_wp_error($guid) ? $guid : (isset($this->by_guid[$guid]) ? $this->by_guid[$guid] : false);
	}

	function cache($doc, $res) {
		if ($res) $this->cache[$doc->key()] = $this->by_uuid[$doc->ID] = $res;
		return $res;
	}

	function postForDoc($doc) {
		return $this->postForGUID(
			$doc->ID           ? $doc->ID : (
			$this->allowCreate ? $this->newID($doc) : (
			$doc->filenameError('missing_guid', __( 'Missing or Empty `ID:` field in %s', 'postmark'))))
		);
	}

	function newID($doc) {
		$guid = $doc->ID = 'urn:uuid:' . wp_generate_uuid4();
		return $doc->save() ? $guid : $doc->filenameError('save_failed', __( 'Could not save new ID to %s', 'postmark'));
	}

	function postTypeOk($post_type) {
		return isset($this->post_types[$post_type]) && !isset($this->exclude_types[$post_type]);
	}

}
