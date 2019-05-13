# Gravity Forms Extension for Postmark

This [imposer](https://github.com/dirtsimple/imposer#readme) state module extends Postmark with the ability to import revision-controlled Gravity Forms export files.  (It's also an example of a Postmark extension: see the [Implementation](#implementation) section below.)

## Installation, Use, & Requirements

This state module requires the Gravity Forms plugin to be installed and activated, and the Gravity Forms CLI to be installed, along with Postmark and Imposer.  It's bundled with Postmark, so the only thing you need to do to activate it is `require "dirtsimple/postmark/gravity-forms"` from inside a `shell` block in your `imposer-project.md` (or another state module that's loaded by the imposer-project file).

Once this is done, and `imposer apply` has been run at least once, future postmark imports will sync certain markdown files as Gravity Forms instead of posts.

There are two ways to designate a file as a form for this extension to process:

* You can save a form's JSON in a file whose name ends with `.gform.md`
* You can embed the encoded form as YAML in the `Gravity-Form` front-matter field of a normal .md file

The first way is generally easier, as it allows you to export or update forms as easily as:

~~~sh
$ wp gf form get 27 >content/example.gform.md   # Export form 27 as a .gform.md file
~~~

The resulting file can then be put under revision control for deployment.

## Implementation

### ID Management and Caching

Because Gravity forms are identified only by a database-specific number, this module uses an option (`gform_postmark_guid`) to map Gravity form IDs to unique GUIDs.  Whenever a Gravity form is loaded during a WP-CLI command (e.g. imposer, postmark, etc.), a GUID is generated and saved unless one is already present in the option.  The GUID is injected into the in-memory copy of the form, so that any export of the form will include the GUID.

Doing this ensures that exported forms won't be duplicated upon their first import, since the imported file will have a matching GUID, causing it to overwrite the existing form rather than creating a new one.

```php cli
add_action('gform_form_post_get_meta', function ($form) {
	if ( ! $gform_id = rgar($form, 'id') ) return $form;
	if ( ! $guid = dirtsimple\Postmark\Option::pluck(['gform_postmark_guid', $gform_id]) ) {
		dirtsimple\Postmark\Option::patch(
			['gform_postmark_guid', $gform_id], $guid = "urn:uuid:" . wp_generate_uuid4(), 'no'
		);
	}
	$form['__postmark_guid__'] = $guid;
	return $form;
});
```

Postmark also needs to be able to look up forms by **etag**: a special hash value associated with markdown documents' contents.  Another option (`gform_postmark_etag`) is used to map from Gravity form IDs to their last synced etag.  When postmark begins a sync process, this filter hook will flip the option so that it's mapping from etags to identifiers (instead of the other way around), and returns database IDs of the form `gform:NNN`.

```php cli
add_filter('postmark_etag_cache', function ($cache) {
	return $cache + array_map(
		function($id) { return "gform:$id"; },
		array_flip( get_option( 'gform_postmark_etag', [] ) )
	);
});
```

(Initially, this option will be empty, but later on during the [form sync process](#importing-a-form), both of the options will be updated for each form that's created or updated.)

When forms are deleted, however, the form's guid and etag must both be removed from the relevant options:

```php tweak
add_action('gform_after_delete_form', function($gform_id){
	$delete = function ($opt) use ($gform_id) {
		unset( $opt[$gform_id] );
		return $opt;
	};
	dirtsimple\Postmark\Option::edit( 'gform_postmark_guid', $delete, [], 'no' );
	dirtsimple\Postmark\Option::edit( 'gform_postmark_etag', $delete, [], 'no' );
});
```

### File Handling

Whenever Postmark loads a markdown file, we check to see if its filename ends in `.gform.md`.  If so, then we populate a `Gravity-Form` front-matter field from the file's body, stripping any `json` fence, if present, and deleting the body afterward.  In the case of a Gravity Forms export file, there is no front matter and no fencing, so the entire file contents are parsed as JSON.  If the JSON doesn't include a Postmark GUID, a default is set based on the filename.

If any markdown file processed by postmark contains a `Gravity-Form` field (regardless of filename), it's checked for a Postmark GUID (which is then removed), and the file's `ID` is defaulted to that GUID, if not explicitly set.  This ensures that the imported form metadata will be "clean" when imported to the database  (i.e., not including a possibly-conflicting GUID).  In particular, this means that manually adding an `ID:` field to a copy of an existing import file will safely duplicate the form under a new ID, even if the original import file contained an embedded ID.

```php cli
add_action('postmark_load', function($doc){
	$name = explode('.', basename($doc->filename));
	if ( array_pop($name) === 'md' && array_pop($name) === 'gform' && count($name) ) {
		if ( ! $form = $doc->get('Gravity-Form') ) {
			$form = json_decode($doc->unfence('json'), true);
			if ( ! isset($form['id']) && isset($form[0]) && is_array($form[0]) ) $form = $form[0];
			$doc['Gravity-Form'] = $form;
			$doc->body = '';
		}
		# Set default GUID from filename, if needed
		if ( ! array_key_exists('__postmark_guid__', $form) )
			$doc['Gravity-Form']['__postmark_guid__'] = "x-gform-key:" . implode('.', $name);
	}
	if ( $guid = rgar($doc->get('Gravity-Form'), '__postmark_guid__') ) {
		# If there's no manually assigned ID, default it from the embedded GUID
		$doc->setdefault('ID', $guid);
		# Ensure the import data can't conflict with a manually-assigned ID
		unset( $doc['Gravity-Form']['__postmark_guid__'] );
	}
});
```

### Importing A Form

If Postmark doesn't have an etag on file for a document, it will need to actually sync it.  We filter the sync handler for documents with a `Gravity-Form` field, so that our custom handler will be used.

```php cli
# If a document has a `Gravity-Form` field, use our handler instead of the default
add_filter('postmark_sync_handler', function($handler, $doc) {
    return $doc->has('Gravity-Form') ? 'gform_postmark_sync' : $handler;
}, 10, 2);
```

The custom handler looks up the document `ID` in the GUID list option.  If an existing form id is found, that form is updated using the GF CLI; if not, a new form is created.  Both the GUID and etag options are then updated to reflect the document `ID` and etag associated with the corresponding Gravity form.  The return value from a sync handler should be a `WP_Error`, or a unique database ID for the object synced.  (In this case, we use `gform:NNN`, as plain numeric values are reserved by Postmark for actual post objects.)

```php cli
function gform_postmark_sync($doc) {
	# Lookup existing form id for the document ID
	$ids = array_flip( get_option( 'gform_postmark_guid', [] ) );
	$gform_id = empty($ids[$doc->ID]) ? 0 : $ids[$doc->ID];

	$form = $doc['Gravity-Form'];

	if ( $gform_id ) {
		$cli = new GF_CLI_Form();
		$cli->update([$gform_id], ['form-json' => json_encode($form)]);
	} else {
		$gform_id = GFAPI::add_form($form);
		if ( is_wp_error($gform_id) ) return $gform_id;
	}

	# Save the ID and etag for the form in options for future lookups
	dirtsimple\Postmark\Option::patch( ['gform_postmark_guid', $gform_id], $doc->ID,     'no' );
	dirtsimple\Postmark\Option::patch( ['gform_postmark_etag', $gform_id], $doc->etag(), 'no' );
	return "gform:$gform_id";
}
```
