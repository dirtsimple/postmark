<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\Imposer;
use dirtsimple\imposer\Pool;
use dirtsimple\imposer\PostModel;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\WatchedPromise;

class Database {

	protected $cache, $allowCreate, $post_types, $docs, $results;

	function __construct($cache=true, $allowCreate=true) {
		# ensure Imposer and all hooks/tasks are initialized first
		Imposer::instance();
		$this->allowCreate = $allowCreate;
		$this->post_types = array_fill_keys(get_post_types(), 1);

		$this->cache = array();
		if ( $cache ) {
			foreach ( static::kind() as $kind ) {
				$this->cache = $this->cache + (array) $kind->etags();
			}
		}

		$this->docs = new Bag();

		$this->results = new Pool(function($filename, $pool) {
			$doc = $this->doc($filename);

			# Valid ID?
			if ( is_wp_error( $guid = $this->guidForDoc($doc) ) ) {
				return $guid;
			}

			$ref = $pool[$filename] = new WatchedPromise;
			$ref->call(function() use ($doc) {
				$kind = static::kind($doc->kind());
				$this->cache[$doc->etag()] = yield $kind->import($doc);
			});
			return $ref;
		});
	}

	static function kind($name = null) {
		# Return a named kind, or all kinds
		static $kinds, $conf;
		if ( ! isset($conf) ) {
			$conf  = new Pool( function($name) { return new Kind(); } );
			$kinds = new Bag();
			do_action("postmark_resource_kinds", $conf);
		}
		if ( ! isset($name) ) return array_map(
			array(__CLASS__, 'kind'), array_keys( (array) $conf )
		);
		return $kinds->get($name) ?: $kinds[$name] = new KindImpl($name, $conf[$name]);
	}

	static function legacy_filter($types) {
		return apply_filters('postmark_excluded_types', $types);
	}

	static function lookup_by_option_guid($guid) {
		return Option::postFor($guid) ?: null;
	}

	function doc($filename) {
		return Document::fetch($this->docs, $this, $filename);
	}

	function sync($filename, $callback=null) {
		# Default callback just passes result through
		$callback = $callback ?: function($already, $res) { return $res; };

		$doc = $this->doc($filename);
		if ( isset($this->cache[$etag = $doc->etag()]) ) {
			return $callback(false, $ret = $this->cache[$etag]);
		}

		$res = $this->results[$doc->realpath()];
		$ret = Promise::now($res, $sentinel = (object) array());
		if ( $ret !== $sentinel ) return $callback(true, $ret);
		return $res->then( function($ret) use ($callback) {
			return $callback(true, $ret);
		});
	}

	function exportMeta($filename) {
		$doc = $this->doc($filename);
		return static::kind($doc->kind())->exportMeta($doc);
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
		return Project::injectGUID($doc->filename(), $guid) ? $doc->load(true)->ID : $doc->filenameError('save_failed', __( 'Could not save new ID to %s', 'postmark'));
	}

	static function export($key, $dir='') {
		$kind = "@wp-post";
		$keyType = "";
		if ( substr($key, 0, 1) === '@' ) {
			$parts = explode(':', $key, 3);
			if ( count($parts) == 3 ) {
				list($kind, $keyType, $key) = $parts;
			} else {
				return new \WP_Error(
					sprintf(
						__( '"%s" is not a valid Imposer reference (should be in "@reskind:keyType:key" format)', 'postmark'),
						$key
					)
				);
			}
		}
		if ( is_numeric($key) && ($keyType == 'id' || $keyType == '') ) {
			$id = (int) $key;
		} else {
			$id = Imposer::resource($kind)->lookup($key, $keyType);
		}
		return isset($id) ? static::kind( substr($kind,1) )->export($id, $dir) : false;
	}

}
