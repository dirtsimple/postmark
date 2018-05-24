## Import Documents During Imposer Apply

This state file extends [imposer](https://github.com/dirtsimple/imposer#readme) to support importing trees of markdown documents during `imposer apply`.  To use it, `require dirtsimple/postmark` from a `shell` block in your `imposer-project.md` or another state file, then use any of these API functions to mark directories for import:

```shell
postmark-module()  { __postmark-set modules content "$1"; }
postmark-content() { __postmark-set content modules "$1"; }

__postmark-set() {
	FILTER '%s as $tmp | .postmark.'$1'[$tmp] = true | del(.postmark.'$2'[$tmp])' "$3"
}
```

Use `postmark-module` *directory* on directories that contain prepackaged content that postmark should not modify, e.g. `postmark-module "vendor/some/package/content"`.  Use `postmark-content` *directory* on directories containing content that postmark is allowed to modify (i.e., to add an autogenerated `ID:` field).

### Implementation

The implementation just runs the trees with the specified options: first the modules, then the content.

```json
{"postmark": {"modules": {}, "content": {}}}
```

```php
add_action('imposer_impose_postmark', function ($data) {
	if ($data) $cmd = new dirtsimple\Postmark\PostmarkCommand;
	if ($trees = $data['modules']) $cmd->tree(array_keys($trees), array('skip-create'=>true));
	if ($trees = $data['content']) $cmd->tree(array_keys($trees), array());
}, 10, 1);
```
