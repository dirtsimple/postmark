<?php
namespace dirtsimple\Postmark;

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
		return is_wp_error($guid) ? $guid : (isset($this->by_guid[$guid]) ? $this->by_guid[$guid] : Option::postFor($guid));
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

	function export($post_spec, $dir='.') {
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

		$md = $this->exportPost($post, $guid);
		$slug = apply_filters('postmark_export_slug', $md->Slug, $md, $post, $dir);
		$suff = '';
		while (
			file_exists($fn = "$dir$slug$suff.md") &&
			( MarkdownFile::fromFile($fn)->ID != $md->ID )
		) $suff--;
		$res = file_exists($fn) ? $md->saveAs($fn) : $md->dump($fn);
		return $res ? $fn : false; # XXX new WP_Error
	}

	protected function exportPost($post, $guid) {
		$id = $post->ID;
		$md = new MarkdownFile;
		$md->body = $post->post_content;

		$md->ID = $guid ?: $post->guid;
		$md->Title = $post->post_title;
		$md->Slug = $post->post_name;

		$md->Author = get_user_by('id', $post->post_author)->user_email;
		$md->Date = $post->post_date_gmt . " UTC";
		$md->Updated = $post->post_modified_gmt . " UTC";

		$md->Excerpt = $post->post_excerpt;

		$md->{'WP-Type'} = $post->post_type;

		$status = $post->post_status;
		if ($status === 'publish' || $status === 'draft') {
			$md->Draft = ($status === 'draft');
		} else {
			$md->Status = $status;
		}

		$terms = array_reduce(
			wp_get_object_terms( array($id), get_taxonomies() ),
			function ($terms, $term) {
				$terms[$term->taxonomy][]=$term->name;
				return $terms;
			},
			array()
		);
		if ( isset( $terms['category'] ) ) {
			$md->Category = $terms['category'];
			unset( $terms['category'] );
		}
		if ( isset( $terms['post_tag'] ) ) {
			$md->Tags = $terms['post_tag'];
			unset( $terms['post_tag'] );
		}
		if ( $terms ) $md->{'WP-Terms'} = $terms;

		$md->Password = $post->post_password;
		$md->Comments = $post->comment_status;
		$md->Pings    = $post->ping_status;
		if ( isset($post->page_template) ) $md->Template = $post->page_template;

		$meta = array_reduce(
			array_keys( get_post_meta($id) ),
			function ($m, $k) use ($id) { $m[$k] = get_post_meta($id, $k, true); return $m; },
			array()
		);
		unset( $meta['_wp_page_template'], $meta['_edit_last'], $meta['_edit_lock'], $meta['_thumbnail_id'] );
		$md->{'Post-Meta'} = apply_filters('postmark_export_meta', $meta, $md, $post);

		do_action('postmark_export', $md, $post);
		return $md;
	}

}
