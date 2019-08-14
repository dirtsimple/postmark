<?php
namespace dirtsimple\Postmark;

use dirtsimple\fn;
use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Imposer;
use dirtsimple\imposer\PostModel;
use dirtsimple\imposer\TermModel;
use Rarst\WordPress\DateTime\WpDateTime;
use Rarst\WordPress\DateTime\WpDateTimeZone;
use WP_CLI;
use WP_Error;

class PostImporter {

	static function register_kind($kind) {
		global $wpdb;
		$filter = PostModel::posttype_exclusion_filter();
		$kind->setImporter(array(__CLASS__, "sync_doc"));
		$kind->setExporter(array(PostExporter::class, "export_post"));
		$kind->setEtagQuery(
			"SELECT post_id, meta_value FROM $wpdb->postmeta, $wpdb->posts
			 WHERE meta_key='_postmark_cache' AND post_id=ID AND $filter"
		);
	}

	static function sync_doc($doc) {
		$self = new PostImporter($doc);
		return $self->sync();
	}

	function __construct($doc) {
		$this->doc = $doc;
		$this->postinfo = Imposer::define('@wp-post', $doc->ID, 'guid');
	}

	function parent_id() {
		if ( ! $doc = $this->doc->parent() ) return null;  # root, no parent
		return $doc->sync();
	}

	function splitTitle() {
		$html = $this->postinfo['post_content'] ?: '';
		if ( preg_match('"^\s*<h([1-6])>(.*?)</h\1>(.*)"is', $html, $m) ) {
			$this->postinfo['post_content'] = $m[3];
			return $m[2] ?: '';
		}
	}

	function formatExcerpt() {
		return $this->doc->html('Excerpt');
	}

	function splitExcerpt() {
		# XXX split on a <!--more-->?  <hr/>?
	}

	function author_id() {
		$email = apply_filters('postmark_author_email', $this->doc->Author, $this->doc);
		if ( is_wp_error($email) ) return $email;
		return Imposer::ref('@wp-user', $email);
	}

	function checkPostType($pi) {
		return (
			!isset($pi['post_type']) || $this->postTypeOk($pi['post_type']) ||
			$this->syncField( 'wp_error', $this->doc->filenameError('excluded_type', __("Excluded or unregistered post_type '%s'",'postmark'), $pi['post_type']) )
		);
	}

	function postTypeOk($post_type) {
		return post_type_exists($post_type) && !isset( PostModel::nonguid_post_types()[$post_type] );
	}

	function post_date() {     return $this->_parseDate('post_date_gmt',     $this->doc->Date); }
	function post_modified() { return $this->_parseDate('post_modified_gmt', $this->doc->Updated); }

	protected function _parseDate($gmtField, $date) {
		$date = new WpDateTime($date, WpDateTimeZone::getWpTimezone());
		$this->syncField( $gmtField, $date->setTimezone(new WpDateTimeZone('UTC'))->format('Y-m-d H:i:s') );
		return $date->format('Y-m-d H:i:s');	// localtime version
	}

	function syncField($field, $value, $is_callback=null) {
		$postinfo = $this->postinfo;
		if ( isset($postinfo['wp_error']) ) return false;
		if ( ! isset($postinfo[$field]) ) {
			if ( func_num_args()>2 ) $value = isset($is_callback) ? $value() : $is_callback;
			if ( isset($value) ) {
				if ( $field != 'wp_error' && is_wp_error($value) )
					return $this->syncField('wp_error', $value);
				$postinfo[$field] = $value;
			}
		}
		return $field != 'wp_error';
	}

	function sync() {
		$doc = $this->doc;
		$postinfo = $this->postinfo;

		# Avoid nested action calls by ensuring parent is synced first:
		$pid = $this->parent_id();
		if ( ! $this->syncField('post_parent', $pid) ) return $pid;

		do_action('postmark_before_sync', $doc, $postinfo);

		if ( $this->_syncinfo_meta($this->doc) && $this->_syncinfo_content($this->doc) ) {
			# Allow before-sync hook to override/patch Post-Meta before this runs
			if ( $doc->has('Post-Meta') ) {
				foreach ( $doc['Post-Meta'] as $key => $val ) {
					if ( $val === null ) $postinfo->delete_meta($key);
					else $postinfo->set_meta($key, $val);
				}
			}
			$postinfo->apply();
			$postinfo->also(function() use($doc, $postinfo) {
				global $wpdb;
				$id = yield $postinfo->ref();

				# Updating a post doesn't update its guid, so we might have to force it
				if ( get_post_field('guid', $id) !== $doc->ID ) {
					$post = array('guid'=>$doc->ID, 'post_type'=>get_post_field('post_type', $id));
					# Fix the GUID in the db and cache
					$wpdb->update( $wpdb->posts, $post, array('ID'=>$id) );
					PostModel::on_save_post($id, (object) $post);
				}

				do_action('postmark_after_sync', $doc, get_post($id));

				$this->_save_opts( $doc->get('Set-Options'), $id);
				$postinfo->set_meta('_postmark_cache', $doc->etag());
				if ($keypath = Option::parseIdURL($doc->ID)) Option::patch($keypath, $id);
			});
			return $postinfo->ref();
		}
		return $postinfo['wp_error'];
	}

	protected function _save_opts($opts, $id) {
		$opts = (array) $opts;
		foreach ($opts as $opt) {
			$keypath = Option::parseIdURL("urn:x-option-id:$opt");
			Option::patch($keypath, $id);
		}
	}

	protected function _syncinfo_meta($doc) { return (
		$this->syncField( 'guid',            $doc->ID       ) &&
		$this->syncField( 'post_name',       $doc->Slug     ) &&
		$this->syncField( 'post_title',      $doc->Title    ) &&
		$this->syncField( 'menu_order',      $doc->Weight   ) &&
		$this->syncField( 'post_status',     $doc->Draft    ? 'draft' : null ) &&
		$this->syncField( 'post_status',     $doc->Status   ) &&
		$this->syncField( 'page_template',   $doc->Template ) &&
		$this->syncField( 'ping_status',     $doc->Pings    ) &&
		$this->syncField( 'comment_status',  $doc->Comments ) &&
		$this->syncField( 'post_password',   $doc->Password ) &&
		$this->syncField( 'post_type',       $doc->{'WP-Type'}   ) &&
		$this->syncField( 'tax_input',       $doc->{'WP-Terms'}  ) &&
		$this->syncField( 'post_mime_type',  $doc->{'MIME-Type'} ) &&
		$this->syncField( 'tags_input',     (array) $doc->Tags     ?: null ) &&
		$this->syncField( 'post_category',   array($this, '_category_ids'), $doc->Category ) &&
		$this->syncField( 'post_name',       array($doc,  'slug'),       true ) &&
		$this->syncField( 'post_author',     array($this, 'author_id'),     $doc->Author  ) &&
		$this->syncField( 'post_date',       array($this, 'post_date'),     $doc->Date    ) &&
		$this->syncField( 'post_modified',   array($this, 'post_modified'), $doc->Updated ) &&
		# XXX to_ping, pinged, file, context, post_content_filtered, _thumbnail_id, ...
		( do_action('postmark_metadata', $this->postinfo, $doc) || true ) );
	}

	protected function _syncinfo_content($doc) {
		$new_or_non_draft = empty($this->postinfo['ID']) || $doc->Draft === false;
		$is_css = $this->postinfo->get('post_type') === 'custom_css';
		return
		$this->syncField( 'post_status',  $new_or_non_draft ? 'publish'   : null ) &&
		$this->syncField( 'post_content', $is_css ? $doc->unfence('css') : null ) &&
		$this->syncField( 'post_content', array($doc,  'html'),          true ) &&
		$this->syncField( 'post_title',   array($this, 'splitTitle'),    true ) &&
		$this->syncField( 'post_excerpt', array($this, 'formatExcerpt'), $doc->Excerpt ) &&
		$this->syncField( 'post_excerpt', array($this, 'splitExcerpt'),  true ) &&
		( do_action('postmark_content', $this->postinfo, $doc) || true ) &&
		$this->checkPostType($this->postinfo);
	}

	function _category_ids() {
		$cats = $this->doc->Category;
		if ( ! is_array($cats) ) $cats = explode( ',', trim( $cats, " \n\t\r\0\x0B," ) );
		$res = Imposer::resource('@wp-category-term')->set_model(TermModel::class);
		return array_map(
			function($v) use ($res) { return is_numeric($v) ? $v : $res->ref($v); },
			$cats
		);
	}
}
