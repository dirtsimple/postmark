# Gravity Forms Extension for Postmark

This [imposer](https://github.com/dirtsimple/imposer#readme) state module extends Postmark with the ability to import revision-controlled Gravity Forms export files.  (It's also an example of a Postmark extension: see the [Implementation](#implementation) section below.)

## Installation, Use, & Requirements

This state module requires the Gravity Forms plugin to be installed and activated, and the Gravity Forms CLI to be installed, along with Postmark and Imposer.  Since this state module is bundled with Postmark, the only thing you need to do to activate it is `require "dirtsimple/postmark/gravity-forms"` from inside a `shell` block in your `imposer-project.md` (or another state module that's loaded by it).

Once this is done, and `imposer apply` has been run at least once, future postmark imports will sync certain markdown files as Gravity Forms instead of posts.  These files must have a `Resource-Kind` of `@gform`, either specified directly in the file, or by its prototype.

If you set the resource kind via a prototype (e.g. `_prototypes/gform.type.yml`), the you can simply save an exported form's JSON to a file that ends with `.gform.md`, e.g.:

~~~sh
# Create a prototype for files ending in `.gform.md`
$ echo 'Resource-Kind: @gform' >content/_prototypes/gform.type.yml

# Export form 27 as a .gform.md, using the Gravity Forms CLI
$ wp gf form get 27 >content/example.gform.md
~~~

The resulting file can then be put under revision control for deployment.

(Alternately, you can manually encode the form as YAML in the `Gravity-Form` field of a normal .md file with a `@gform` resource kind, but this isn't likely to be very useful.)

## Implementation

### ID Management and Caching

Because Gravity forms are identified only by a database-specific number, this module uses an option (`gform_postmark_guid`) to map Gravity form IDs to unique GUIDs.  Whenever a Gravity form is loaded during a WP-CLI command (e.g. imposer, postmark, etc.), a GUID is generated and saved unless one is already present in the option.  The GUID is injected into the in-memory copy of the form, so that any export of the form will include the GUID.

Doing this ensures that exported forms won't be duplicated upon their first import, since the imported file will have a matching GUID, causing it to overwrite the existing form rather than creating a new one.

```php cli
use dirtsimple\Postmark\Option;

add_action('gform_form_post_get_meta', function ($form) {
	if ( ! $gform_id = rgar($form, 'id') ) return $form;
	if ( ! $guid = Option::pluck(['gform_postmark_guid', $gform_id]) ) {
		Option::patch(
			['gform_postmark_guid', $gform_id], $guid = "urn:uuid:" . wp_generate_uuid4(), 'no'
		);
	}
	$form['__postmark_guid__'] = $guid;
	return $form;
});
```

Postmark also needs to be able to look up forms by **etag**: a special hash value associated with markdown documents' contents.  Another option (`gform_postmark_etag`) is used to map from Gravity form IDs to their last synced etag.  (Initially, this option will be empty, but later on during the [form sync process](#importing-a-form), both of the options will be updated for each form that's created or updated.)

When forms are deleted, however, the form's guid and etag must both be removed from the relevant options:

```php tweak
add_action('gform_after_delete_form', function($gform_id){
	foreach( array('gform_postmark_guid', 'gform_postmark_etag') as $opt ) {
		$val = get_option($opt, []);
		if ( array_key_exists($gform_id, $val) ) {
			unset( $val[$gform_id] );
			update_option($opt, $val, 'no');
		}
	}
});
```

### Document Parsing

Whenever Postmark loads a markdown document whose resource kind is `@gform`, we populate a `Gravity-Form` front-matter field from the file's body, stripping any `json` fence, if present, and deleting the body afterward.  (In the case of a Gravity Forms export file, there is no front matter and no fencing, so the entire file contents are parsed as JSON, assuming the file is named for a prototype that sets the `Resource-Kind` to `@gform`.)

The document's `ID` is defaulted to the Postmark GUID found in the JSON, or a default is set based on the filename.  But if the JSON contains a Postmark GUID, it's always removed,  so that the imported form metadata will be "clean" when imported to the database  (i.e., not including a possibly-conflicting GUID).

(In particular, this means that manually adding an `ID:` field to a copy of an existing import file will safely duplicate the form under a new ID, even if the original import file contained an embedded ID.)

```php cli
add_action('postmark load @gform', function($doc){
	if ( ! $form = $doc->get('Gravity-Form') ) {
		$form = json_decode($doc->unfence('json'), true);
		if ( ! isset($form['id']) && isset($form[0]) && is_array($form[0]) ) $form = $form[0];
		$doc['Gravity-Form'] = $form;
		$doc->body = '';
	}
	# Set default GUID from filename, if needed
	if ( ! array_key_exists('__postmark_guid__', $form) ) {
		$doc['Gravity-Form']['__postmark_guid__'] = "x-gform-key:" . basename($doc->filename);
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

If Postmark doesn't have an etag on file for a document, it will need to actually sync it.  To do this, it needs to know how to import the `@gform` resource kind, so we register an import handler for it, and request automatic ID-to-etag mapping via the `gform_postmark_etag` option:

```php cli
add_action('postmark_resource_kinds', function($kinds) {
	$kinds['@gform']->setImporter('gform_postmark_sync')->setEtagOption('gform_postmark_etag');
});
```

The custom handler looks up the document `ID` in the GUID list option.  If an existing form id is found, that form is updated using the GF CLI; if not, a new form is created.  Both the GUID and etag options are then updated to reflect the document `ID` and etag associated with the corresponding Gravity form.  The return value from a sync handler should be a `WP_Error`, or a unique ID for the object synced.

```php cli
function gform_postmark_sync($doc) {
    # Index GUID => ID
	$ids = array_flip( get_option( 'gform_postmark_guid', [] ) );

	if ( $gform_id = rgar($ids, $doc->ID, 0) ) {
		$cli = new GF_CLI_Form();
		$cli->update([$gform_id], ['form-json' => json_encode($doc['Gravity-Form'])]);
	} else {
		$gform_id = GFAPI::add_form($doc['Gravity-Form']);
		if ( is_wp_error($gform_id) ) return $gform_id;
	}

	# Save the GUID of the form for future lookups
	Option::patch( ['gform_postmark_guid', $gform_id], $doc->ID, 'no' );
	return $gform_id;
}
```
