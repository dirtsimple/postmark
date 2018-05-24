<?php
namespace dirtsimple\Postmark;

use Rarst\WordPress\DateTime\WpDateTime;
use Rarst\WordPress\DateTime\WpDateTimeZone;
use WP_Error;

class Document extends MarkdownFile {

	/* Lazy-loading Markdown file that knows how to sync w/a Database */

	protected $id, $db, $loaded=false;
	public $filename, $postinfo, $is_template;

	function __construct($db, $filename, $is_tmpl=false) {
		$this->db = $db;
		$this->filename = $filename;
		$this->is_template = $is_tmpl;
	}

	function load() {
		if (! $this->loaded) {
			$this->loadFile( $this->filename );
			$this->loaded = true;
			Project::load($this, $this->db);
		}
		return $this;
	}

	function __get($key) { return $this->load()->meta($key); }
	function __set($key, $val) { $this->load()->meta[$key]=$val; }
	function __isset($key) { return isset($this->load()->meta[$key]); }

	function key()    { return Project::cache_key($this->filename); }
	function synced() { return $this->db->cachedPost($this); }
	function exists() { return ($id = $this->current_id()) && ! is_wp_error($id); }

	function current_id() {
		$id = $this->id ?: $this->synced() ?: $this->db->postForDoc($this);
		return is_wp_error($id) ? $id : $this->id = $id;
	}

	function save() {
		return $this->saveAs($this->filename);
	}

	function post_id() {
		return $this->exists() ? $this->id : $this->sync();
	}

	function file_exists() {
		return file_exists($this->filename);
	}

	function parent() {
		return Project::parent_doc($this->db, $this->filename);
	}

	function parent_id() {
		if ( ! $parent = $this->parent() ) return null;
		if ( ! $parent->file_exists() ) return $parent->parent_id();
		return $parent->post_id();
	}

	function slug() {
		return Project::slug($this->filename);
	}

	function splitTitle() {
		$html = $this->postinfo['post_content'] ?: '';
		if ( preg_match('"^\s*<h([1-6])>(.*?)</h\1>(.*)"is', $html, $m) ) {
			$this->postinfo['post_content'] = $m[3];
			return $m[2] ?: '';
		}
	}

	function html($propName='body') {
		return Formatter::format($this, $propName, $this->{$propName});
	}

	function formatExcerpt() {
		return $this->html('Excerpt');
	}

	function splitExcerpt() {
		# XXX split on a <!--more-->?  <hr/>?
	}

	function author_id() {
		$email = apply_filters('postmark_author_email', $this->Author, $this);
		if ( is_wp_error($email) ) return $email;
		if ( $user = get_user_by('email', $email) ) return $user->ID;
		return new WP_Error(
			'bad_author',
			sprintf(
				__('Could not find user with email: %s (Author: %s)', 'postmark'),
				$email, $this->Author
			)
		);
	}

	function checkPostType($pi) {
		return (
			!isset($pi['post_type']) || $this->db->postTypeOk($pi['post_type']) ||
			$this->syncField( 'wp_error', new WP_Error('excluded_type', sprintf(__("Excluded or unregistered post_type '%s' in %s",'postmark'), $pi['post_type'], $this->filename)))
		);
	}

	function post_date() {     return $this->_parseDate('post_date_gmt',     $this->Date); }
	function post_modified() { return $this->_parseDate('post_modified_gmt', $this->Updated); }

	protected function _parseDate($gmtField, $date) {
		$date = new WpDateTime($date, WpDateTimeZone::getWpTimezone());
		$this->syncField( $gmtField, $date->setTimezone(new WpDateTimeZone('UTC'))->format('Y-m-d H:i:s') );
		return $date->format('Y-m-d H:i:s');	// localtime version
	}

	function syncField($field, $value, $cb=null) {
		$postinfo = & $this->postinfo;
		if ( isset($postinfo['wp_error']) ) return false;
		if ( ! isset($postinfo[$field]) ) {
			if ( func_num_args()>2 ) $value = isset($cb) ? $value() : $cb;
			if ( isset($value) ) {
				if ( $field != 'wp_error' && is_wp_error($value) )
					return $this->syncField('wp_error', $value);
				$this->postinfo[$field] = $value;
			}
		}
		return $field != 'wp_error';
	}

	function sync() {
		# Avoid nested action calls by ensuring parent is synced first:
		if ( is_wp_error( $pid = $this->parent_id() ) ) return $pid;
		if ( is_wp_error( $res = $this->current_id() ) ) return $res;
		$this->postinfo = array(
			'post_parent' => $pid,
			'meta_input' => array('postmark_cache' => $this->key()),
		);
		do_action('postmark_before_sync', $this);
		if ( $this->_syncinfo_meta() && $this->_syncinfo_content() ) {
			$args = wp_slash( $this->postinfo );
			add_filter( 'wp_revisions_to_keep', array($this, '_revkeep'), 999999, 2 );
			$res = empty($args['ID']) ? wp_insert_post($args, true) : wp_update_post($args, true);
			remove_filter( 'wp_revisions_to_keep', array($this, '_revkeep'), 999999, 2 );
			if (!is_wp_error($res)) {
				$this->db->cache($this, $this->id = $this->postinfo['ID'] = $res);
				do_action('postmark_after_sync', $this, get_post($res));
			}
			return $res;
		}
		return $this->postinfo['wp_error'];
	}

	function _revkeep($num, $post) {
		return ( $num && $post->guid == $this->ID ) ? 0 : $num;
	}

	protected function _syncinfo_meta() { return (
		$this->syncField( 'guid',            $this->ID       ) &&
		$this->syncField( 'post_name',       $this->Slug     ) &&
		$this->syncField( 'post_title',      $this->Title    ) &&
		$this->syncField( 'menu_order',      $this->Weight   ) &&
		$this->syncField( 'post_status',     $this->Draft    ? 'draft' : null ) &&
		$this->syncField( 'post_status',     $this->Status   ) &&
		$this->syncField( 'page_template',   $this->Template ) &&
		$this->syncField( 'ping_status',     $this->Pings    ) &&
		$this->syncField( 'comment_status',  $this->Comments ) &&
		$this->syncField( 'post_password',   $this->Password ) &&
		$this->syncField( 'post_type',       $this->{'WP-Type'}   ) &&
		$this->syncField( 'tax_input',       $this->{'WP-Terms'}  ) &&
		$this->syncField( 'post_mime_type',  $this->{'MIME-Type'} ) &&
		$this->syncField( 'post_category',  (array) $this->Category ?: null ) &&
		$this->syncField( 'tags_input',     (array) $this->Tags     ?: null ) &&
		$this->syncField( 'ID',              array($this, 'current_id'), true ) &&
		$this->syncField( 'post_name',       array($this, 'slug'),       true ) &&
		$this->syncField( 'post_author',     array($this, 'author_id'),     $this->Author  ) &&
		$this->syncField( 'post_date',       array($this, 'post_date'),     $this->Date    ) &&
		$this->syncField( 'post_modified',   array($this, 'post_modified'), $this->Updated ) &&
		# XXX to_ping, pinged, file, context, post_content_filtered, _thumbnail_id, ...
		$this->postinfo = apply_filters('postmark_metadata', $this->postinfo, $this) );
	}

	protected function _syncinfo_content() { return (
		$this->syncField( 'post_status',  ( empty($this->postinfo['ID']) || $this->Draft === false ) ? 'publish' : null ) &&
		$this->syncField( 'post_content', array($this, 'html'),          true ) &&
		$this->syncField( 'post_title',   array($this, 'splitTitle'),    true ) &&
		$this->syncField( 'post_excerpt', array($this, 'formatExcerpt'), $this->Excerpt ) &&
		$this->syncField( 'post_excerpt', array($this, 'splitExcerpt'),  true ) &&
		$this->postinfo = apply_filters('postmark_content', $this->postinfo, $this) ) &&
		$this->checkPostType($this->postinfo);
	}

	function filenameError($code, $message) {
		return new WP_Error($code, sprintf($message, $this->filename));
	}
}
