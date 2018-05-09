<?php
namespace dsi\Postmark;
use Mustangostang\Spyc;
use League\CommonMark\Block;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment;
use League\CommonMark\Extension;
use League\CommonMark\Inline;
use Rarst\WordPress\DateTime\WpDateTime;
use Rarst\WordPress\DateTime\WpDateTimeZone;
use WP_CLI;
use WP_Error;

/**
 * Sync posts or pages from static markdown files
 *
 */
class PostmarkCommand extends \WP_CLI_Command {

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
		$repo = $this->repo($flags);
		$this->sync_docs( array_map( array($repo, 'doc'), $args ), $flags );
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
		$repo = $this->repo($flags);
		foreach ( $args as $arg )
			$this->sync_docs( $repo->docs(trailingslashit($arg) . "*.md"), $flags, $arg );
	}

	/**
	 * Generate a unique ID for use in a markdown file
	 */
	function uuid( $args ) { WP_CLI::line('urn:uuid:' . wp_generate_uuid4()); }


	// -- non-command utility methods --

	protected function repo($flags) {
		return new Repo(
			! WP_CLI\Utils\get_flag_value($flags, 'force', false),
			! WP_CLI\Utils\get_flag_value($flags, 'skip-create', false)
		);
	}




	protected function result($doc, $res, $porcelain, $already=true) {
		if ( is_wp_error( $res ) )
			WP_CLI::error($res);
		elseif ( $porcelain )
			WP_CLI::line($res);
		elseif ( $already )
			WP_CLI::debug("$doc->filename already synced", "postmark");
		else
			WP_CLI::success("$doc->filename successfully synced, ID=$res", "postmark");
	}

	protected function sync_docs($docs, $flags, $dir=null) {
		if ( empty($docs) && isset($dir) ) {
			$dir = realpath($dir);
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












class MarkdownFile {
	/* A MarkdownFile is a combination of front matter and body */

	public $meta=array(), $body='';

	function parse($text) {
		$meta = '';
		$body = $text;
		if ( preg_match("{^(?:---)[\r\n]+(.*?)[\r\n]+(?:---)[\r\n]+(.*)$}s", $text, $m) === 1) {
			$meta = $m[1]; $body = $m[2];
		}
		$this->meta = ! empty(trim($meta)) ? Spyc::YAMLLoadString(trim($meta)) : array();
		$this->body = $body;
		return $this;
	}

	function loadFile($file) { return $this->parse(file_get_contents($file)); }

	function dump($filename=null) {
		$data = sprintf("%s---\n%s", Spyc::YAMLDump( $this->meta, 2, 0 ), $this->body);
		return isset($filename) ? file_put_contents($filename, $data, LOCK_EX) : $data;
	}

	function saveAs($filename) {
		if ( copy($filename, "$filename.bak") ) {
			$r1 = $this->dump($filename); $r2 = unlink("$filename.bak");
			return $r1 && $r2;
		}
	}

	function meta($key=null, $default=null) {
		if ($key) {
			if ( array_key_exists($key, $this->meta) ) return $this->meta[$key];
			else return $default;
		} else return $this->meta;
	}

	function __get($key) { return $this->meta($key); }
	function __set($key, $val) { $this->meta[$key]=$val; }
}

class Document extends MarkdownFile {

	/* Lazy-loading Markdown file that knows its repo + path */

	protected $id, $repo, $loaded=false, $key;
	public $filename, $postinfo;

	function __construct($repo, $filename) {
		$this->repo = $repo;
		$this->filename = $filename;
	}

	function path() { return $this->repo->splitpath($this->filename)[1]; }
	function key() {
		if ($this->key) return $this->key;
		$file = $this->filename;
		return $this->key = $this->path() . ':' . filesize($file) . ':' . filemtime($file);
	}

	function load() {
		if (! $this->loaded) {
			$this->loadFile( $this->filename )->loaded = true;
			do_action('postmark_load', $this);
		}
		return $this;
	}

	function __get($key) { return $this->load()->meta($key); }
	function __set($key, $val) { $this->load()->meta[$key]=$val; }

	function synced() { return $this->repo->postForKey($this->key()); }
	function exists() { return $id = $this->current_id() && ! is_wp_error($id); }

	function current_id() {
		$id = $this->id ?: $this->synced() ?: $this->repo->postForDoc($this);
		return is_wp_error($id) ? $id : $this->id = $id;
	}

	function save() { $this->key=null; return $this->saveAs($this->filename); }


	function post_id() {
		return $this->exists() ? $this->id : $this->sync();
	}

	function file_exists() {
		return file_exists($this->filename);
	}

	function parent() {
		$dir = dirname($file = $this->filename);
		if ( basename($file) == 'index.md' ) {
			if ( is_project($dir) ) return null;
			$dir = dirname($dir);
		}
		return $this->repo->doc($dir == '.' ? 'index.md' : "$dir/index.md");
	}

	function parent_id() {
		if ( ! $parent = $this->parent() ) return null;
		if ( ! $parent->file_exists() ) return $parent->parent_id();
		return $parent->post_id();
	}

	function slug() {
		$slug = basename($this->filename, '.md');
		if ( $slug == 'index' ) {
			$slug = dirname($this->filename);
			if ( $slug == dirname($slug) ) return null;  # at root
			$slug = basename($slug, '.md');
		}
		return $slug;
	}

	function splitTitle() {
		$html = $this->postinfo['post_content'] ?: '';
		if ( preg_match('"^\s*<h([1-6])>(.*?)</h\1>(.*)"im', $html, $m) ) {
			$this->postinfo['post_content'] = $m[3]; return $m[2] ?: '';
		}
	}


	function html($propName='body') {
		return $this->repo->format($this, $propName, $this->{$propName});
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
				__('Could not find user with email: %s (Author: %s)'),
				$email, $this->Author
			)
		);
	}


	function post_date() {
		return $this->_parseDate('post_date_gmt',     $this->Date);
	}

	function post_modified() {
		return $this->_parseDate('post_modified_gmt', $this->Updated);
	}

	protected function _parseDate($gmtField, $date) {
		$date = new WpDateTime($date, WpDateTimeZone::getWpTimezone());
		$this->syncField( $gmtField, $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') );
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
		if ( is_wp_error( $res = $this->parent_id() ) ) return $res;
		if ( is_wp_error( $res = $this->current_id() ) ) return $res;
		$this->postinfo = array(
			'post_parent' => $res,
			'meta_input' => array('postmark_cache' => $this->key()),
		);
		do_action('postmark_before_sync', $this);
		if ( $this->_syncinfo_meta() && $this->_syncinfo_content() ) {
			$args = wp_slash( $this->postinfo );
			add_filter( 'wp_revisions_to_keep', array($this, '_revkeep'), 999999, 2 );
			$res = empty($args['ID']) ? wp_insert_post($args, true) : wp_update_post($args, true);
			remove_filter( 'wp_revisions_to_keep', array($this, '_revkeep'), 999999, 2 );
			if (!is_wp_error($res)) {
				$this->repo->cache($this, $this->id = $this->postinfo['ID'] = $res);
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
		$this->syncField( 'post_status',  empty($this->postinfo['ID']) ? 'active' : null ) &&
		$this->syncField( 'post_content', array($this, 'html'),          true ) &&
		$this->syncField( 'post_title',   array($this, 'splitTitle'),    true ) &&
		$this->syncField( 'post_excerpt', array($this, 'formatExcerpt'), $this->Excerpt ) &&
		$this->syncField( 'post_excerpt', array($this, 'splitExcerpt'),  true ) &&
		$this->postinfo = apply_filters('postmark_content', $this->postinfo, $this) );
	}

	function filenameError($code, $message) {
		return new WP_Error($code, sprintf($message, $this->filename));
	}
}



class Repo {
	protected $cache, $by_guid, $converter, $roots, $allowCreate;

	function __construct($cache=true, $allowCreate=true) {
		$this->reindex($cache);
		$this->roots = array();
		$this->allowCreate = $allowCreate;
	}

	function reindex($cache) {
		global $wpdb;
		$filter = "post_status <> 'trash' AND post_type <> 'revision'";
		$this->by_guid = $this->_index("SELECT ID, guid FROM $wpdb->posts WHERE $filter");
		if ( $cache ) $this->cache = $this->_index(
			"SELECT post_id, meta_value FROM $wpdb->postmeta, $wpdb->posts
			 WHERE meta_key='postmark_cache' AND post_id=ID AND $filter"
		); else $this->cache = array();
	}

	protected function _index($query) {
		global $wpdb; return array_column( $wpdb->get_results($query, ARRAY_N), 0, 1 );
	}

	function doc($path) { return new Document($this, realpath($path)); }
	function docs($pat) { return array_map(array($this, 'doc'), rglob($pat)); }

	function postForKey($key) {
		if ( isset($this->cache[$key]) ) return $this->cache[$key];
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

	function format($doc, $field, $value) {
		$markdown = apply_filters('postmark_markdown', $value, $doc, $field);
		$html = $this->formatter()->convertToHtml($markdown);
		return apply_filters('postmark_html', $html, $doc, $field);
	}

	function formatter() {
		if ($this->converter) return $this->converter;

		$cfg = array(
			'renderer' => array(
				'block_separator' => "",
				'inner_separator' => "",
				'line_break' => "",
			),
			'extensions' => array(
				'Webuni\CommonMark\TableExtension\TableExtension' => null,
				'Webuni\CommonMark\AttributesExtension\AttributesExtension' => null,
			),
		);
		$env = Environment::createCommonMarkEnvironment();
		$cfg = apply_filters('postmark_formatter_config', $cfg, $env);

		$this->addExtensions($env, $cfg['extensions']);
		unset( $cfg['extensions'] );
		return $this->converter = new CommonMarkConverter($cfg, $env);
	}

	protected function addExtensions($env, $exts) {
		foreach ($exts as $ext => $args) {
			if ( false === $args ) continue;
			$extClass = new \ReflectionClass($ext);
			$this->addExtension($env, $ext, $extClass->newInstanceArgs((array) $args));
		}
	}

	protected function addExtension($env, $name, $ext) {
		switch (true) {
		case $ext instanceof Extension\ExtensionInterface                 : $env->addExtension($ext);         break;
		case $ext instanceof Block\Parser\BlockParserInterface            : $env->addBlockParser($ext);       break;
		case $ext instanceof League\CommonMark\DocumentProcessorInterface : $env->addDocumentProcessor($ext); break;
		case $ext instanceof Inline\Parser\InlineParserInterface          : $env->addInlineParser($ext);      break;
		case $ext instanceof Inline\Processor\InlineProcessorInterface    : $env->addInlineProcessor($ext);   break;
		default: WP_CLI::error("Unrecognized extension type: $name");
		}
	}

	protected function __root($file) {
		return $this->roots[$dir = dirname($file)] = (
			isset($this->roots[$dir])      ? $this->roots[$dir] : (
			( $dir == $file )              ? $dir : (
			is_project($dir)               ? dirname($dir) : (
			$this->__root($dir))))
		);
	}

	function splitpath($file) {
		$file = realpath($file);
		$root = $this->__root($file);
		$path = substr($file, strlen($root)+1);
		return array($root, $path);
	}

}





function rglob($pat, $f=0) {
	$files = glob($pat, $f);
	$dir = dirname($pat);
	$dir = $dir == '.' ? '' : trailingslashit($dir);
	foreach ( glob("$dir*", GLOB_ONLYDIR|GLOB_NOSORT) as $dir ) {
		$files = array_merge( $files, rglob($dir . '/'. basename($pat), $f) );
	}
	return $files;
}

function basename( $path, $suffix = '' ) {
	# locale-independent basename
	return urldecode( \basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
}

function realpath($path) {
	# Handle not-existing paths
	return (
		( false === ($p = \realpath($path)) )
		? trailingslashit(realpath(dirname($path))) . basename($path)
		: $p
	);
}

function is_project($dir) {
	return (
		file_exists("$dir/.postmark") ||
		file_exists("$dir/.git") ||
		file_exists("$dir/.hg") ||
		file_exists("$dir/.svn")
	);
}









