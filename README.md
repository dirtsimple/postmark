Sync Markdown Files to WordPress Posts and Pages
-------------------

Static site generators let you use a revision-controlled tree of markdown files to make a site, but don't offer a lot of themes or dynamic features.  Wordpress has lots of themes and dynamic features, but locks up your content in HTML embedded in a database, where you can't use your own editor or revision control anything.

So why not **combine** the two?

Postmark is a [wp-cli](https://wp-cli.org/) command that takes a markdown file (or entire tree of them) and creates or updates posts, pages, and [other database objects](#postmark_resource_kinds) in Wordpress.  Some key features include:

* No webserver configuration changes required: files can be synced from any directory on the server, and don't need to be writable or even readable by the web server.  (They do need to be readable by the user running the wp-cli command, though!)
* Files are synced using a GUID to identify the post or page in the DB, so the same files can be applied to multiple wordpress sites (e.g. dev/staging/prod, or common pages across a brand's sites), and moving or renaming a file changes its parent or slug in WP, instead of creating a new page or post.
* Files can contain YAML front matter to set all standard Wordpress page/post properties
* Custom post types are allowed, and plugins can use actions and filters to support custom fields or other WP data during sync.
* Plugins' special pages (like checkout or "my account") and themes' [custom CSS](#custom-css) can be synced using [Option References](#option-references), and plugins' HTML options can be synced using [Option HTML Values](#option-html-values)
* [Prototypes and Templating](#prototypes-and-templating): In addition to their WP post type, files can have a `Prototype`, from which they inherit properties and an optional Twig template that can generate additional static content using data from the document's front-matter.
* Posts or pages are only updated if the size, timestamp, name, location, or contents of an input file (or its prototype/template files) are changed (unless you use `--force`)
* Works great with almost any file-watching tool (like entr, gulp, modd, reflex, guard, etc.) to update edited posts as soon as you save them, with only the actually-changed files being updated even if your tool can't pass along the changed filenames.
* Markdown is converted using [league/commonmark](league/commonmark) with the [table](https://github.com/webuni/commonmark-table-extension#syntax) and [attribute](https://github.com/webuni/commonmark-attributes-extension#syntax) extensions, and you can add other extensions via filter.  Markdown content can include shortcodes, too.  (Though you may need to backslash-escape adjacent opening brackets to keep them from being treated as markdown footnote links.  Shortcode openers or closers that are on a line by themselves will be placed outside any paragraphs, divs, tables, etc. that they break, precede, or follow.)
* Parent posts or pages are supported (nearest `index.md` above a file becomes its parent, recursively)
* Slugs default to the filename (or directory name for `index.md`), unless otherwise given
* Post/page titles default to the first line of the markdown body, if it's a heading

Postmark is similar in philosophy to [imposer](https://github.com/dirtsimple/imposer), in that synchronization is always one-way (from the filesystem to the database) but does not overwrite any database contents that aren't specified by the input file(s).  So any part of a post or page that's not in the markdown or YAML (such as comments) are unaffected by syncing.

(Postmark is also similar to imposer in that it's pre-installed by [mantle](https://github.com/dirtsimple/mantle).  Mantle projects include an optional file watching daemon that detects changes to markdown files in the project's `content/` directory, and automatically syncs them to your DB.)

### Contents

<!-- toc -->

- [Overview](#overview)
  * [Installation and Use](#installation-and-use)
  * [File Format and Directory Layout](#file-format-and-directory-layout)
  * [The `ID:` Field](#the-id-field)
  * [Front Matter Fields](#front-matter-fields)
  * [Working With Options](#working-with-options)
    + [Option References](#option-references)
    + [Custom CSS](#custom-css)
    + [Option HTML Values](#option-html-values)
- [Prototypes and Templating](#prototypes-and-templating)
  * [Template Processing](#template-processing)
  * [Inheritance and Template Re-Use](#inheritance-and-template-re-use)
- [Change Detection](#change-detection)
- [Imposer Integration](#imposer-integration)
- [Exporting Posts, Pages, and Other Resources](#exporting-posts-pages-and-other-resources)
  * [Updating Exported Documents](#updating-exported-documents)
  * [Updating Exported HTML](#updating-exported-html)
  * [Updating Other Fields](#updating-other-fields)
- [Actions and Filters](#actions-and-filters)
  * [Markdown Formatting](#markdown-formatting)
  * [Document Objects](#document-objects)
    + [postmark load *resource-kind*](#postmark-load-resource-kind)
    + [postmark_resource_kinds](#postmark_resource_kinds)
  * [Sync Actions for Posts](#sync-actions-for-posts)
    + [postmark_before_sync](#postmark_before_sync)
    + [postmark_metadata](#postmark_metadata)
    + [postmark_content](#postmark_content)
    + [postmark_after_sync](#postmark_after_sync)
  * [Sync Actions for Options](#sync-actions-for-options)
    + [postmark_before_sync_option](#postmark_before_sync_option)
    + [postmark_after_sync_option](#postmark_after_sync_option)
  * [Export Actions and Filters](#export-actions-and-filters)
    + [postmark_export_meta](#postmark_export_meta)
    + [postmark_export_meta_$key](#postmark_export_meta_key)
    + [postmark_export](#postmark_export)
    + [postmark_export_slug](#postmark_export_slug)
    + [postmark update wp-post](#postmark-update-wp-post)
  * [Other Filters](#other-filters)
    + [postmark_author_email](#postmark_author_email)
    + [postmark_excluded_types](#postmark_excluded_types)
- [Project Status/Roadmap](#project-statusroadmap)

<!-- tocstop -->

## Overview

### Installation and Use

Postmark can be installed via:

```shell
wp package install dirtsimple/postmark
```

The two main commands Postmark provides are:

* `wp postmark sync <file>... [--force] [--skip-create] [--porcelain]`
* `wp postmark tree <dir>...  [--force] [--skip-create] [--porcelain]`

The `sync` command creates or updates posts or pages matching the given `.md` file(s), while the `tree` command processes all `.md` files within the named directories (and all their subdirectories).  The `--porcelain` option makes the output silent except for the Wordpress post/page/option IDs of the synced files.  (Which means you can get a file's Wordpress post ID or option ID by passing its filename to `wp postmark sync --porcelain`.)

By default, posts and pages are not updated unless the `.md` file has changed (or been moved/renamed) since the last time it was successfully synced, but `--force` overrides that and syncs all named files or directories, whether changed or not.  (This can be useful if you add or remove plugins that affect how posts are converted or formatted.)  If a file is incompletely synced due to an error, it will be be tried again the next time a similar command is run, even without using `--force`.

To sync a markdown file with Wordpress, the file's front matter must include a globally unique identifier in the `ID` field.  If this value is missing, Postmark will add it automatically, unless you use the `--skip-create` option (in which case you'll get an error message instead).

To add an `ID`, Postmark must be able to write to both the file and the directory in question (to save a backup copy of the file during the change), so you should use `--skip-create` if those permissions are not available to the wp-cli user.  (Also, Postmark assumes that your front matter is formatted in such a way that adding an `ID:` line to the top of the front matter will not create a syntax error, i.e. that your top-level YAML is not wrapped in `{}` or anything else.)

The other commands Postmark provides are:

* `wp export [<spec>...] [--dir=<output-dir>] [--porcelain] [--allow-none]` -- export the specified post(s), pages, or other resources to markdown files.  (See [Exporting Posts, Pages, and Other Resources](#exporting-posts-pages-and-other-resources), below, for more info.)
* `wp update [<file>...] [--porcelain] [--allow-none]` -- export selected state information from the database as `.pmx.yml` files next to the specified markdown documents, so that complex properties (such as page builder data) can be configured via the WordPress GUI, but still be revision-controlled as a text file.  (For more info, see the section below on [Updating Exported Documents](#updating-exported-documents).)
* `wp postmark uuid [<file>...]` -- adds an `ID:` field to each *file* that doesn't already have one.  If no files are given,  it outputs a new UUID to standard output, suitable for use as the `ID:` of a new `.md` file.  (See [The ID: Field](#the-id-field) below, for more info.)

### File Format and Directory Layout

Postmark expects to see non-empty markdown files with an `.md` extension and YAML front matter.  If a file is named `index.md`, it will become the Wordpress page/post parent of any other files in that directory or any subdirectory that don't contain its own `index.md`.  If not overridden in the YAML fields, the default slug of a post or page is its filename, minus the `.md` extension.  If the filename is `index.md`, the name of the containing directory is used instead.

When syncing any individual `.md` file, Postmark searches upward until a matching `index.md` file is found, or a "project root" directory is found.  (A project root is any directory containing a `.git`, `.hg`, `.svn`, `_postmark`, or `.postmark` subdirectory.)  The first such `index.md` found becomes the parent page or post of the current `.md` file.  (And it is synced if it doesn't exist in the Wordpress database yet, searching recursively upward for more parents until every parent `index.md` exists and is made the parent of the corresponding child post or page.)

Postmark input files do not need to be placed under your Wordpress directory or even accessible by your webserver.  For security, they should not be *writable* by your webserver, and do not even need to be *readable* except by the user running `wp postmark` commands.  You also do not have to place all your markdown files in a single tree: the `postmark sync` and `postmark tree` commands accept multiple file and directory names, respectively.

Postmark input files use standard YAML (v1.2) front matter, delineated by `---` before and after, like this:

```markdown
---
ID: urn:uuid:1e30ea5f-17fe-422a-9c24-cb591eb2d72d
Draft: true
---
## Content Goes Here

If no `Title` was given, the above heading is stripped from the post body and used instead.
But if a `Title` *was* given, the heading remains in place.
```

Content is converted from Markdown to HTML using league/commonmark, and the formatting process can be extended using the [actions and filters for markdown formatting](#markdown-formatting).

### The `ID:` Field

All front matter fields are optional, except for `ID:`, which *must* contain a globally unique identifier, preferably in the form of a [uuid](https://en.wikipedia.org/wiki/Universally_unique_identifier).  (You can generate suitable values using `wp postmark uuid`.)  By default, Postmark will automatically add the field to new markdown files, unless you use `--skip-create` or Postmark is unable to write to the file or directory.

The purpose of ths identifier is to allow postmark to match a file with an existing page or post in the Wordpress database, or else know that it needs to create a new one with that identifier.  (Post ID numbers are not sufficient for this purpose, since they can vary across wordpress installations, and Wordpress's internally-generated URL-based "guids" are often changed during migration across installations.)

### Front Matter Fields

In addition to the required `ID:` field, you can also include any or all of the following *optional* fields to set the corresponding data in Wordpress.  Any fields that are not included, or which have a null or missing value, will not be changed from their current value in Wordpress:

```yaml
Title: # if missing, it's parsed from the first heading if the content starts with one
Slug:  # if missing, will be obtained from file/directory name

Category: something          # this can be a comma-delimited string, or a YAML list
Tags:     bar, baz           # this can be a comma-delimited string, or a YAML list
Author:   foo@example.com    # user id is looked up by email address or user login

Excerpt: |  # You can set a custom excerpt, which can contain markdown
  Some *amazing* blurb that makes you want to read this post!

Date: 2017-12-11 13:41 PST        # Dates can be anything recognized by PHP, and
Updated: April 30, 2018 3:46pm    # use Wordpress's timezone if no zone is given

Status:    # string, Wordpress `post_status`
Draft:     # unquoted true/false/yes/no -- if true or yes, overrides Status to `draft`
Template:  # string, Worpdress `page_template`
WP-Type:   # string, Wordpress `post_type`, defaults to 'post'

WP-Terms:  # Worpdress `tax_input` -- a map from taxonomy names to terms:
  some-taxonomy: term1, term2   # terms can be a string 
  other-taxonomy:               # or a YAML list
    - term1
    - term2

Comments:   # string, 'open' or 'closed', sets Wordpress `comment_status`
Password:   # string, sets Wordpress `post_password`
Weight:     # integer, sets Wordpress `menu_order`
Pings:      # string, 'open' or 'closed', sets Wordpress `ping_status`
MIME-Type:  # string, sets Wordpress `post_mime_type`

Post-Meta:  # array of meta keys -> meta values; only the given values are changed
  a_custom_field: "Good stuff!"
  _some_hidden_field: 42
  delete_me: null  # setting to null deletes the meta key

Set-Options:  # an option path or array of option paths; each will be set to the post's db ID
  - edd_settings/purchase_history_page  # e.g., make this page the "my account" page for both EDD
  - lifterlms_myaccount_page_id         # and LifterLMS.  See "Working With Options" for more info

HTML:  # override markdown conversion for specific fields
  Excerpt: "<p>This is html</p>"   # a string means, "use this HTML instead of what's in the field"
  body: true                       # non-false non-string means, "field is HTML, not markdown"
```

Please note that Postmark only validates or converts a few of these fields.  Most are simply passed to Wordpress as-is, which may create problems if you use an invalid value.  (For example, if you assign a custom post type that isn't actually installed, or a status that the post type doesn't support.)  In most cases, however, you can fix such problems simply by changing the value to something valid and re-syncing the file.

Wordpress plugins or wp-cli packages can add extra fields (or change the handling of existing fields) by registering actions and filters.

### Working With Options

Many Wordpress plugins have special pages (like carts, checkouts, "my account", etc.) that are referenced in their settings as a post ID.  Wordpress itself has an option to set the home page (i.e., `page_on_front`).  And sometimes there are options that require HTML, but which you'd like to be able to express as markdown, perhaps in a revision-controlled file.

Postmark provides three ways to integrate with Wordpress options like these:

* You can set one or more wordpress options (or portions thereof) to the database ID of the document on sync, by putting option paths in the `Set-Options:` front matter field of the document.  (For example, `Set-Options: page_on_front` would make the document the Wordpress home page.)
* You can update a possibly already existing, plugin-supplied post or page in place by using a `urn:x-option-id:` URL as the `ID:` of the document.  (The option is set whenever the document is synced, and if there's no existing post/page, it's created.)
* You can set an option (or portion thereof) to the HTML generated by a document, by using a `urn:x-option-value:` URL as the document's `ID: `.

(In addition, the first two integration methods can actually be *combined*: you can update a plugin-supplied default page in-place using a `urn:x-option-id:` URL as the document `ID:`, *and* then point other options to the same page using the document's `Set-Options:` field.)

Regardless of which approach you take in a given document, to work with options you will be using *option paths*.  An option path is a  `/`-separated path that begins with a Wordpress option name.  Any path segments after the first are treated as array keys to traverse sub-items within the option, and all path segments must be urlencoded if they contain anything other than alphanumerics, `-` and `_`.  (e.g. the path `foo/bar%2fbaz` refers to the `bar/baz` key of the `foo` option.)

For the `Set-Options:` field, you will only need to place an option path (or array of them) to update the relevant options or portions thereof.  For the `ID:` field, you will need to prefix the path with `urn:x-option-id:` or `urn:x-option-value:`, to distinguish the case where the post ID is stored in the option, from the case where the HTML will be stored in the option.

#### Option References

When a plugin creates a default page whose post ID is stored in an option, you can update that page in place by setting your markdown document's `ID:` to a  `urn:x-option-id:` URL, e.g.:

```yaml
ID: "urn:x-option-id:edd_settings/purchase_page"  # use this page as the EDD checkout page
```

A post with a `urn:x-option-id:` URL as its `ID` will be synced slightly differently than normal.  As usual, if a post with the given GUID exists, the markdown file is synced into that post.  Afterward, the specified option will be edited to reflect the Wordpress post_id of that that post.  (In the above example, the `purchase_page` key under the `edd_settings` option will be created or changed).

However, if no post with the given GUID exists, the option value (e.g. the `purchase_page` key under the `edd_settings` option) will be checked for a valid post ID.  If the value exists and references an existing post, **the existing post's GUID will be changed** and its contents overwritten by the sync.  (This allows you to replace the contents of the default page(s) generated by a plugin when it's first activated, without manual intervention or creating a duplicate post.)

Other examples of option references you may find useful:

**AffiliateWP**
* `urn:x-option-id:affwp_settings/affiliates_page`

**Easy Digital Downloads**
* `urn:x-option-id:edd_settings/failure_page`
* `urn:x-option-id:edd_settings/purchase_page`
* `urn:x-option-id:edd_settings/purchase_history_page`
* `urn:x-option-id:edd_settings/success_page`

**LifterLMS**
* `urn:x-option-id:lifterlms_checkout_page_id`
* `urn:x-option-id:lifterlms_memberships_page_id`
* `urn:x-option-id:lifterlms_myaccount_page_id`
* `urn:x-option-id:lifterlms_shop_page_id`
* `urn:x-option-id:lifterlms_terms_page_id`

**WooCommerce**
* `urn:x-option-id:woocommerce_cart_page_id`
* `urn:x-option-id:woocommerce_checkout_page_id`

(Note: this list is likely far from comprehensive, even for the plugins listed.  Also, since new releases of the above plugins could potentially add, rename, or remove any of these settings, you should always test your sync to a non-production database before updating plugins in production.)

#### Custom CSS

Wordpress stores custom CSS for themes in a post of type `custom_css`, and saves the post ID in an option.  So you can sync a theme's custom CSS from a markdown file using an `ID:` of `urn:x-option-id:theme_mods_THEME/custom_css_post_id`, where `THEME` is the theme's slug.

As with other [option references](#option-references), if there is an existing option value with the post id of an existing post, that post will be updated with your markdown file's contents.  Or, if the value isn't set (or is the default of `-1`), a new post will be created, and the option value updated to point to the new post.

So, to define custom CSS for the Hestia theme, you would create a markdown file like this:

~~~markdown
---
ID:       urn:x-option-id:theme_mods_hestia/custom_css_post_id
WP-Type:  custom_css
Title:    hestia
Slug:     hestia
Comments: closed
Pings:    closed
Status:   publish
---

```css

/* CSS Content Goes Here */

```
~~~

The `css` code fence wrapping is optional: it is automatically removed if found on posts of type `custom_css`.  (This is done so that you can take advantage of any CSS-specific editing or highlighting features of your markdown editor.)  You can fence with either backquotes or tildes (`~`), as long as there are at least three, the opening and closing fences are the same length and not indented, and the first word on the opening fence line is `css` in lower case.

#### Option HTML Values

Some Wordpress plugins have options containing HTML content, that you might prefer to write using Markdown and/or maintain under revision control.  You can sync files to these settings using a `urn:x-option-value:` URL in each document's `ID:`, e.g.:

```yaml
ID: "urn:x-option-value:edd_settings/purchase_receipt"
```

A post with the above `ID:` will be synced by converting the document body to HTML, and then saving the result to the `purchase_receipt` key of the `edd_settings` option.  Most other front matter is ignored (except for that used by [prototypes and templating](#prototypes-and-templating)) and *no actual post is created*, so only the [markdown formatting](#markdown-formatting) and [option sync](#sync-actions-for-options) hooks are invoked during the process, and the command output will list the `ID:` instead of a Wordpress numeric post ID.

Since options don't have meta fields, the sync timestamp for options is kept in a (non-autoload) option, `postmark_option_cache`, thereby avoiding unnecessary updates for unchanged documents.  (It is safe to delete this option, however, since the only effect will be to effectively `--force` the next resync of any documents whose `ID:` is an option value.)

## Prototypes and Templating

In some cases, you may have a lot of documents with common field values or structure.  You can keep your  project DRY (i.e., Don't Repeat Yourself) by creating *prototypes*.  For example, if you have a lot of "video" pages that contain one or more videos with some introductory text, you could make file(s) like this:

```markdown
---
Prototype: video
Videos:
 - title: First Video
   url: https://youtube.com?view=example
 - title: Second Video
   url: https://vimeo.com/something
---
# Example Videos

Dude, check these out!
```

Then, in the same directory or a parent, create a `_postmark/` or `.postmark/` directory containing a `video.type.yml` file with the common properties:

```yaml
WP-Type: post
Draft: false
Category: videos
Author: me@example.com
```

and a `video.type.twig` file, containing a [Twig template](https://twig.symfony.com/doc/2.x/templates.html) for the body text:

```markdown
{{ body }}

{% for video in Videos %}
## {{ video.title }}
[video src="{{ video.url }}"]
{% endfor %}
```

Then, every document with  `Prototype: video` in its front matter will have the specified post type, category, and author, as well as being formatted by adding any items listed in `Videos:` after the body.

Or, if you'd rather specify the type using just one file, you can combine the properties and template into a single `video.type.md` file, putting the properties in front matter, and the Twig template (if any) in the body.  (Similar to `custom_css` posts, the body can optionally be wrapped in a fenced code block with a language of `twig`, if you want to take advantage of twig-specific editing or highlighting support in your markdown editor.)

If a `.type.md` file exists alongside a `.type.yml` and/or `.type.twig`, then the properties in `.type.yml` override those in `.type.md`, and the template in `.type.twig` *wraps* the output of the template in `.type.md`.

### Template Processing

Twig templates (in `.type.twig` or `.type.md`) are used to generate *markdown* (not HTML), possibly containing Wordpress shortcodes as well.  Templates are processed statically at *sync time*, not during Wordpress page generation, and only have access to data from the document being synced.  The "variables" supplied to the template are the front-matter properties, plus `body` for the body text.

Templates can use full Twig syntax, including macros, the `extends` tag and `include()` function, which means that you can put other template files in your `_postmark` or `.postmark` directory and then use them as partials or base templates, similar to other static site generators.  For example, above we could have done something like this:

```twig
{% from "macros.twig" import video_block %}
{{ body }}

{% for video in Videos %}
{{ video_block(video) }}
{% endfor %}
```

with a `macros.twig` in our `_postmark` or `.postmark` directory containing:

```twig
{% macro video_block(video) %}
## {{ video.title }}
[video src="{{ video.url }}"]
{% endmacro %}
```

### Inheritance and Template Re-Use

A limited form of prototype inheritance is supported: if a prototype has a `.type.md` file with a `Prototype:`, then *that* prototype's properties are treated as defaults for the `.type.md`.  (Recursively, if the second prototype itself has a `Prototype:`).  Only properties are inherited, not templates, since applying a template to a template is unlikely to be useful.  If you need to share a template between multiple prototypes, put it in a separate `.twig` file, and then use Twig's `include()` (or `extends` or `import`) to apply it in each of the places where it's needed.

## Change Detection

To make syncing as fast as possible, Postmark caches information about imported documents in the Wordpress database, and avoids updating the database unless a document (or its prototype file(s)) have actually changed.

The information that Postmark caches includes a hash of the document's front matter and body, after prototypes have been inherited and templates applied.  This ensures that if either the document or its prototype files have changed, then the database will be updated with the new results.

What this process does *not* automatically detect, however, is changes made to plugins, actions, filters, etc. that affect how the document is rendered to HTML or what data will get inserted into the database.  Such changes will not normally be captured unless you use `--force` to sync *all* documents, or you add extra fields to your front matter.

For example, you could add a `Prototype-Version` field to your prototypes, and then change this field's value to trigger changes for all the documents using that prototype.

Of course, that doesn't help you if you're creating a Postmark extension (e.g. in a  wp-cli package, plugin, theme, or Imposer state module).  You can't edit your users' prototype files, assuming you even knew what to edit.

But you *can* "edit" your users' *front-matter* at import time, using the [`postmark load wp-post` action](#postmark-load-resource-kind).  For example:

```php
add_action('postmark load wp-post', function($doc) {
    if ( $doc->has('EDD') ) $doc['EDD-Importer-Version'] = '4.1';
}, 10, 1);

add_action('postmark_metadata', function($postinfo, $doc) {
	if ( ! $doc->get('EDD') ) return;
    // ...  code to import various things to $postinfo
}, 10, 2);
```

In this example, the `postmark load wp-post` handler adds an extra `EDD-Importer-Version` field when a document is loaded that contains an `EDD` field.  This means that if the import semantics for the `EDD` field change, the version can be changed, and then any documents with an `EDD` field will be considered "changed" since their last sync.  In this way, merely upgrading the plugin (or package, state module, theme, etc.) will automatically invalidate caching for the affected documents.

(Note, by the way, that this type of versioning is *only* required for extensions that are altering the HTML formatting or need access to the postinfo object.  If an extension is just providing syntax sugar or remapping fields, and can do everything it needs from the `postmark load wp-post` action, then the remapped fields would already be part of the document hash, and so any change in the remapping process would automatically change the hash of any documents affected by the change.)

## Imposer Integration

Postmark provides a [state module](default.state.md) for optional integration with [imposer](https://github.com/dirtsimple/imposer#readme): just add a shell block like this to your `imposer-project.md`, or any state module that needs to include markdown content as part of its state:

```shell
require "dirtsimple/postmark"      # load the API

# Use postmark-module for prepackaged .md files that should be read-only and have
# ID: values already:

postmark-module "$__DIR__/stuff"   # sync `stuff/` next to this file, with --skip-create option

# Or use postmark-content for writable directories where you might be adding new .md
# files without an existing ID:

postmark-content "my-content"      # sync `my-content/` at the project root
```

You can actually use this to distribute wp-cli packages containing markdown content, and have them automatically loaded into the site(s) that use them.

Note: the `postmark-module` and `postmark-content` functions don't perform an immediate sync when called.  Instead, they record the directory information in the imposer JSON specification object for later parsing during the task-running phase of `imposer apply`.  (See the imposer docs for more on how this works.)

## Exporting Posts, Pages, and Other Resources

To facilitate working with specialized post types and other database resources, postmark provides a `wp postmark export` command, which creates markdown files in a specified directory, given a list of post IDs, GUIDs, URLs, or imposer references.  (e.g. `@my-appt-type:id:285`).  Any [resource kind](#postmark_resource_kinds) can be exported, as long as it has an export function registered.

Each export file is given a name based on its slug (i.e., its `post_name`), possibly with a `-` and a number at the end.  If a file of the given name already exists, it's checked to see if it has the same GUID -- if so, the file is overwritten, otherwise the number is incremented and the next candidate is checked.

(So if, for example, there are ten posts with unique GUIDs being exported with a `post_name` of `foo`, they will end up in `foo.md`, `foo-1.md`, up through `foo-9.md`, and repeated exports of any of the ten will use the same filename as was used before, if none are deleted and the `post_name`s don't change.)

Currently, post content is exported to markdown files *as-is*, without any attempt to translate HTML back to markdown.  In addition, a great many default-valued or empty fields are likely to be included in the YAML front matter.  For this reason, exported posts must be manually edited to resolve these issues.  Alternately, you can use the [export actions and filters](#export-actions-and-filters) to convert or clean up the content during the export process.  (e.g. to remove meta fields that should not be placed under revision control.)

Because the post excerpt and body are stored as HTML in the database, the exported document has the relevant `HTML:` front matter fields set to `true`, so that if the file is imported as-is, the content will not be re-parsed as markdown.  If you do convert the content to markdown, you should remove the relevant entries from the  `HTML:` map, or rename it to `Export-HTML:` if you will be editing the content in WordPress and then saving it with `postmark update`.

Also note: a post's parent, menu order, and MIME type are currently *not* included in its export file, since menu order and MIME type are used only for menu items and attachments, and postmark determines a post's parent (if any) using its directory location.  (The post's `_thumbnail_id` metadata is also excluded, since it references an integer ID that could vary between databases.)

### Updating Exported Documents

Many WordPress plugins and themes provide additional settings or data for posts and pages, that are difficult to manually specify via front matter.  For example, page builders, access restriction tools, page-specific theme options, etc.

To aid in working with these features while still supporting revision control and dev/prod deployment, Postmark provides the `postmark update` command.  The command exports updated post properties in a `.pmx.yml` file that lives next to the original markdown document, that are automatically merged into the post during sync.  A separate file is used to avoid losing comments, spacing, etc. in the main document's front matter, and any fields specified in the front matter override those in the `.pmx.yml` file.

In order for post metadata fields to be exported, they must be listed in the document's `Export-Meta:` front matter (or inherited from a prototype).  For example, putting the following in a document's front matter would allow changes made using Elementor to be saved with `postmark update`, and then applied to the same or another database via `postmark sync` or `postmark tree`:

```yaml
Export-Meta:
  # On `postmark update`, export primary Elementor fields
  _elementor_edit_mode:
  _elementor_template_type:
  _elementor_version:
  _elementor_data:

Post-Meta:
  # Forcibly delete the CSS cache on import, so it won't be stale
  _elementor_css: null

Export-HTML:  # Use HTML from Elementor instead of the markdown body
  body:
```

Note that in order to use this feature safely, you need to have a good understanding of what the various metadata fields supplied by your plugins *do*, so that you don't corrupt data at deployment time.

For example, Elementor includes an `_elementor_css` field that should *always be deleted* at import time, in order to ensure correct CSS, while LifterLMS has an `_llms_num_reviews` field that should never be imported in order to not lose data.  Some plugins may also generate values based on other fields and normally only set them when a post is updated via the UI, not via the command line.  So take care before committing and deploying your `.pmx.yml` files.

An easy way to set up the `Export-Meta` field is to export an existing post or page, then edit the exported file and rename the `Post-Meta:` field to `Export-Meta:`.  Then, delete any fields that shouldn't be reset by importing, and the values from the fields you want to keep.  Once you have a good idea of the needed fields, you may wish to add them to a prototype so you aren't copying them to multiple files.  Individual documents can add any extra fields, or suppress the exporting of fields by setting the value to `false`.  For example, the following will prevent `_some_field` from being exported for the current document, even if its prototype listed it for export:

```yaml
Export-Meta:
  _some_field: false
```

For every non-`false` entry in `Export-Meta`, there will be a corresponding entry in the `Post-Meta` of the `.pmx.yml` export file: either the value of that metadata field, or `null` if the post lacks that field.  This means that on import, such missing fields will be explicitly deleted, removing any dangling value in the database.  This is particularly important for use with plugins that decide things based on the presence or absence of a metadata field, not just its content.

### Updating Exported HTML

If you will be editing or generating a post's content or excerpt using the WordPress GUI (e.g. with Gutenberg or a page builder), you should add an `Export-HTML:` field to your document, listing the fields to export, and remove the corresponding fields from the document (and from the `HTML:` field, if it exists).  So, if you want to create a new document whose content you'll be mostly editing via the Gutenberg editor, you might create a new empty document like this:

```markdown
---
Title: An Example
WP-Type: page

Export-HTML:
  body:
  Excerpt:
---

```

After this document is imported to a WordPress page, you can use `postmark update` to export the HTML for the body and excerpt.

(Conversely, if you've already created the document in WordPress, you can use `postmark export` to create the initial markdown file, but you will then need to edit it and rename the `HTML:` field to `Export-HTML:`, remove the body text and `Excerpt:`, and then run `postmark update` on the file to replace the old body and excerpt in the corresponding `.pmx.yml` file.)

### Updating Other Fields

In addition to metadata and HTML fields, you can allow other fields to be updated from the WordPress GUI when doing a `postmark update`: just add the fields to `Export-Fields:`.  For example, this front matter will cause the `Updated:` and `Tags:` fields to be exported to the `.pmx.yml` file during update:

```markdown
---
Export-Fields:
  Updated:
  Tags:
---
```

Remember, though: fields exported by `postmark update` are only *defaults*: you must remove the corresponding field from the main `.md` file in order for the exported data to be imported.

## Actions and Filters

All of Postmark's actions and filters can be registered from a plugin, theme, wp-cli package, or imposer state module.  Because Postmark is built on [imposer](https://github.com/dirtsimple/imposer), you can hook the `imposer_tasks` action to register other actions and filters -- meaning you can put your imposer or postmark-specific hooks in a separate PHP file and then `require_once` that file, e.g.:

~~~php
add_action('imposer_tasks', function() {
    require_once(__DIR__ . '/includes/cli-hooks.php');
});
~~~

Then, you can put any code to register postmark actions or filters in `includes/cli-hooks.php`, and that file will only be loaded when running `wp postmark` or `imposer`.

### Markdown Formatting

Markdown formatting is controlled by the following filters:

* `apply_filters('postmark_formatter_config', array $cfg, Environment $env)` -- this filter is invoked once per command, to initialize the League/Commonmark [Environment](https://commonmark.thephpleague.com/customization/environment/) and [configuration](https://commonmark.thephpleague.com/configuration/).  Filters can add markdown extensions, parsers, processors, or renderers to the `Environment` object, or return an altered `$cfg` array.  In addition to the standard configuration elements, `$cfg` contains an `extensions` array mapping extension or parser class names to argument arrays (or null).  These extension classes are instantiated using the given argument arrays, and added to `$env`.  An extension can be disabled by setting its value in the `extensions` array to `false`.

  The current default extensions are:

  * [`League\CommonMark\Extension\Table\TableExtension`](https://commonmark.thephpleague.com/1.6/extensions/tables/#syntax), which implements Markdown tables,
  * [`League\CommonMark\Extension\Attributes\AttributesExtension`](https://commonmark.thephpleague.com/1.6/extensions/attributes/#attribute-syntax), which allows adding Kramdown-style HTML attributes to blocks and spans,
  * [`League\CommonMark\Extension\Strikethrough\StrikethroughExtension`](https://commonmark.thephpleague.com/1.6/extensions/strikethrough/), which turns `~~`-wrapped text into `<del>` elements for strikethrough,
  * [`League\CommonMark\Extension\SmartPunct\SmartPunctExtension`](https://commonmark.thephpleague.com/1.6/extensions/smart-punctuation/), which translates dots and hyphens to ellipses and em/en dashes, and converts plain single and double quotes to their left/right versions,
  * [`League\CommonMark\Extension\Autolink\AutolinkExtension`](https://commonmark.thephpleague.com/1.6/extensions/autolinks/), which supports [Github-style autolinking](https://github.github.com/gfm/#autolinks-extension-) of bare URLs and web hostnames,
  * [`League\CommonMark\Extension\TaskList\TaskListExtension`](https://commonmark.thephpleague.com/1.6/extensions/task-lists/), which adds support for [Github-style task lists](https://github.github.com/gfm/#task-list-items-extension-),
  * `dirtsimple\Postmark\ShortcodeParser`, which detects lines that consist solely of shortcode opening or closing tags, and passes them through without markdown interpretation.  (This allows you to enclose markdown blocks within a shortcode, instead of having the shortcode become part of the block itself, which can be problematic when using e.g. conditional tags.)

* `apply_filters('postmark_markdown', string $markdown, Document $doc, $fieldName)` -- this filter can alter the markdown content of a document (or any of its front-matter fields) before it's converted into HTML.  `$fieldName` is `"body"` if `$markdown` came from `$doc->body`; otherwise it is the name of the front matter field being converted.  (Such as `"Excerpt"`, or any custom fields added by plugins.)

* `apply_filters('postmark_html', string $html, Document $doc, $fieldName)` -- this filter can alter the HTML content of a document (or any of its front-matter fields) immediately after it's converted.  As with the `postmark_markdown` filter, `$fieldName` is either `"body"` or a front-matter field name.

Note that `postmark_markdown` and `postmark_html` may be invoked several times or not at all, as they are run whenever `$doc->html(...)` is called.  If a sync filter or action set `$postinfo['post_content']` or `$postinfo['post_excerpt']` before Postmark has a chance to, these filters won't be invoked unless the filter or action uses `$doc->html(...)` to do the conversion.

Also note that if you are adding hooks to *any* of these filters, you should also add formatter versioning info to documents during relevant `postmark load` filters, so that when your extension is added, removed, or updated, any affected documents will be considered "changed" and get re-synced.

### Document Objects

Many filters and actions receive `dirtsimple\Postmark\Document` objects as a parameter.  These objects offer the following API:

* Front-matter fields are accessible as public, writable object properties.  (e.g. `$doc->Foo` returns front-matter field `Foo`) .  Fields that aren't valid PHP property names can be accessed using e.g. `$doc->{'Some-Field'}`.  Missing or empty fields return null; if you want a different default when the field is missing, you can use `$doc->get('Somename', 'default-value')`.
* `$doc->body` is the markdown text of the document, and is a writable property.
* `$doc->html($propName='body')` converts the named property from Markdown to HTML (triggering the `postmark_markdown` and `postmark_html` filters).
* `$doc->has("field")` returns true if the document has "field" as  one of its front matter fields
* `$doc->get("field", $default=null)` returns the content of "field" from the frontmatter, or `$default` if it's not found
* `$doc->select(['field'=>callback, ...])` calls each *callback* if the matching field exists, with the value of that field.  The return value is an array containing only keys for the fields that existed, with the values being the result of calling the callback.  If a callback isn't actually callable (e.g. `true`), the value is returned as-is in the output array.  If a callback is an associative array, it's processed recursively, so that e.g. `$doc->select(['EDD'=>['Price'=>$cb]])` will call `$cb` if and only if there is an `EDD` front matter field that's an associative array with a `Price` subfield.

#### postmark load *resource-kind*

Whenever a document is loaded from disk, `do_action("postmark load $kind", Document $doc)` is run, to allow modification of the document (e.g. its `Post-Meta`) before the document hash is calculated.  Any changes made to the document during this action will affect the hash calculation, so this is the ideal place to do simple syntax sugar or field remappings.

If you're writing an extension that needs to do complex calculations or access the database, however, you should probably use a different hook, and add a versioning field (e.g. `MyPlugin-Version-Info`) during this action in order to ensure that documents get re-synced when your algorithms change.  Likewise, if your extension is altering how markdown formatting is done, you should add a versioning field to ensure that adding, removing, or updating your extension will force affected documents to re-sync.

The default *resource-kind* is `wp-post`, meaning the document is going to be mapped to a Wordpress post, or, if the document has an `x-option-value` URL, the default resource kind is `wp-option-html`.  Other resource kinds can be registered using the [`postmark_resource_kinds` action](#postmark_resource_kinds), and assigned to documents via the `Resource-Kind:` front-matter field (either directly in the document, or via a prototype).

#### postmark_resource_kinds

Postmark isn’t just for importing posts and option values.  In principle, it can be used to import other built-in Wordpress objects (like users or categories) or specialty objects defined by plugins (like Gravity Forms, which are stored in a custom database table).

To determine what kind of object is to be imported, Postmark looks at a document’s `Resource-Kind` field, which is `wp-post` by default (unless an `x-option-value:` URL is used for the `ID:`, in which case the default kind is `wp-option-html`).  But you can override these defaults using a document’s front-matter or via its prototype, so long as a plugin has registered an import handler for that resource kind.

To add other resource kinds, an extension can register a hook for the `postmark_resource_kinds` action, which will receive an array-like object mapping kind names to "kind definition" objects.  Handlers registered for this action can then use configuration methods like `setImporter()`, `setExporter()`, and `setEtagQuery()` to configure the kind.  For example, the code below registers an importer and exporter for a `my_plugin-item` resource kind:

~~~php
add_action('postmark_resource_types', function($kinds) {
    $kinds['my_plugin-item']->setImporter('my_plugin_import_item_from_doc');
    $kinds['my_plugin-item']->setExporter('my_plugin_export_item_to_doc');
});

function my_plugin_import_item_from_doc($doc) {
    # import $doc into database, saving $doc->etag() with it for caching purposes,
    # then return a database ID or WP_Error
}

function my_plugin_export_item_to_doc($md, $id, $dir, $doc=null) {
    # Export an database object whose ID is $id by setting values on the
    # MarkdownFile in $md, returning a slug that will be used to generate
    # the export filename.  Return `false` if $id isn't found, or a WP_Error
    # to signal other error conditions.  If $doc is non-null, the export is
    # an update to an existing document, and $doc can be used to trim or
    # filter the output fields accordingly (e.g. the way posts use `Export-Meta:`).
}

~~~

Whenever a document has a `Resource-Kind:` of `my_plugin-item`, the `my_plugin_import_from_doc()` function will be called to perform the import, replacing Postmark’s builtin sync processing.

In order to avoid needless database updates for unchanged files, Postmark computes an **etag** for a document's contents (which can be obtained via the `$doc->etag()` method).  Importers should save this value in the database upon import, and register a handler to retrieve those etags when a sync begins.

For example, suppose we wanted to make an importer for Wordpress users, but didn't want to update user data when the import file(s) haven't changed.  We would need to register both an importer and an etag query, e.g.:

~~~php
function demo_user_importer($doc) {
    # Create or update a user
    if ( $user_id = email_exists($doc['Email']) ) {
        $user_id = wp_update_user(...);  # ...with appropriate data
    } else {
        $user_id = wp_insert_user(...);  # ...with appropriate data
    }

    # Return error if insert or update failed
    if ( is_wp_error($user_id) ) return $user_id;

    # save $doc->etag() for caching
    update_user_meta($user_id, '_postmark_cache', wp_slash($doc->etag()));
    return $user_id;
}

add_action('postmark_resource_types', function($kinds) {
    global $wpdb;
    $kinds['wp-user']->setImporter('demo_user_importer');
    $kinds['wp-user']->setEtagQuery(
        "SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key='_postmark_cache'"
    );
});
~~~

In the above example, a `_postmark_cache` meta field is used to store the etag, with a simple SQL query to fetch it.  The query used must return the database ID and etag, *in that order*, as its first two fields for each item of the appropriate kind.

Of course, in some cases, there is no way to directly query the database for the necessary information.  For example, Gravity Forms doesn't use Wordpress-style metadata for its forms, so the included [example extension for Gravity Forms](gravity-forms.state.md) uses `setEtagOption('gform_postmark_etag')` instead of `setEtagQuery()` to configure etag handling for the resource kind.  This tells postmark to automatically save and load etags from the specified option.

The downside to this approach is that you will need to write code to remove deleted IDs from the option when the associated database item is deleted.  (This wasn't needed for the user example above, because deleting the user automatically deletes the associated meta field.)

Finally, if neither a database query nor an option will work for your resource kind, you can use `setEtagCallback($callback)` to register a function that will be called with zero arguments and should return an array mapping from the database IDs of your resource kind to their associated etags.  (As with `setEtagQuery()`, your import function will be responsible for storing the etag in the database.)

### Sync Actions for Posts

During the sync process for posts, a document builds up a `$postinfo` array to be passed into `wp_insert_post` or `wp_update_post`.  (Postmark only sets values in the `$postinfo` that have not already been set by an action or filter, so you can prevent it from doing so by setting a value first.)

For example, Postmark calculates the `post_content` after calling the `postmark_metadata` action, but before the `postmark_content` action.  This means you can prevent Postmark from doing its own Markdown-to-HTML conversion by setting `post_content` from either the `postmark_before_sync` action, or the `postmark_metadata` action.

Note: `$postinfo` is not actually a PHP array -- it's a PHP `ArrayObject` subclass with a few extra methods, like `get($key, $default=null)`, `has($key)`, and a few others.  But you can still treat is as a regular array for purposes of setting, getting, or removing items.  You can see the [dirtsimple\\imposer\\Bag class](https://github.com/dirtsimple/imposer/blob/master/src/Bag.php) for info on most of the other available methods, but there are some additional methods you might find helpful:

* `$postinfo->id()` returns the post ID if an existing post is being updated, or null if the post is new.
* `$postinfo->set_meta($key, $val)` -- does an `update_post_meta`, setting `$key` to `$val`.  `$key` can be a string or an array: if it's an array, it's treated as a path to a subitem within the meta field, working much like a key path for the wp-cli `wp post meta patch insert` command, except that parent arrays are automatically created.
* `$postinfo->delete_meta($key)` -- deletes the specified meta key, or if `$key` is an array, it's treated as a path to a sub-item to delete within the meta field, like a key path for the wp-cli `wp post meta patch delete` command.

The following actions run during the sync process (for posts, not options), in the following order:

#### postmark_before_sync

`do_action('postmark_before_sync', Document $doc, PostModel $postinfo)` allows modification of the document (e.g. the `Post-Meta` field) or other actions before it gets synced.  This action can set Wordpress post fields (e.g. `post_author `, `post_type`) in the `$postinfo` object, to prevent Postmark from doing its default translations of those fields.  (The object is mostly empty at this point, however, so reading from it is not very useful.)  Setting `$postinfo->wp_error` to a WP_Error instance will force the sync to terminate with the given error.

#### postmark_metadata

`do_action('postmark_metadata', PostModel $postinfo, Document $doc)` lets you modify the `$postinfo` that will be passed to `wp_insert_post` or `wp_update_post`.  This hook can be used to override or extend the calculation of Wordpress fields based on the front matter.

When this action runs, `$postinfo` is  initialized with any Wordpress field values that Postmark has calculated from the front matter,  or which were set in `$postinfo` by `postmark_before_sync` actions.  It does *not*, however, contain the `post_content` or `post_excerpt` yet, unless set by a previous action or filter.

Functions registered for this action can set the `post_content` or `post_excerpt` in `$postinfo` to pre-empt Postmark from doing so.  They can also set `$postinfo['wp_error']` to a WP_Error object to terminate the sync process with an error.

If `post_content`, `post_title`, `post_excerpt`, or `post_status` remain empty after processing all functions registered for this action, Postmark will supply default values by converting the document body from Markdown to HTML, and/or extracting a title and excerpt as needed.

#### postmark_content

`do_action('postmark_content', $postinfo, Document $doc)` is similar to the `postmark_metadata` action, except that markdown conversion and title/excerpt extraction have already been done, if needed. 

#### postmark_after_sync

`do_action('postmark_after_sync', Document $doc, WP_Post $rawPost)` allows post-sync actions to be run on the document and/or resulting post.  `$rawPost` is a `raw`-filtered Wordpress WP_Post object, reflecting the now-synced post.  This can be used to process front matter fields that require the post ID to be known (e.g. adding data to custom tables).

### Sync Actions for Options

#### postmark_before_sync_option

`do_action('postmark_before_sync_option', Document $doc, array $optpath)` runs before processing documents that sync to an [option HTML value](#option-html-values).  There is no `$postinfo`, since no post will be created or updated.  However, this filter can still access or modify any other properties of the document, for example to preprocess the body in some way before the option is updated.  For convenience, `$optpath` contains the path to the option being synced, e.g. `['edd_settings', 'purchase_receipt']`.

#### postmark_after_sync_option

`do_action('postmark_after_sync_option', Document $doc, array $optpath)` is just like `postmark_before_sync_option`, except that it runs after the option value has been updated from the HTML version of the document body. A typical use of this hook would be to update other options from the document’s front matter,  e.g.:

~~~php
use dirtsimple\Postmark\Option;

add_action('postmark_after_sync_option', function($doc, $optpath){
    if ( $optpath === ['edd_settings', 'purchase_receipt'] ) {
        if ( $doc->has('Title') )  Option::patch(['edd_settings', 'purchase_subject'], $doc->Title);
        if ( $doc->has('Header') ) Option::patch(['edd_settings', 'purchase_heading'], $doc->Header);
    }
}, 10, 2);
~~~

The above example code will run after syncing any document whose ID is `urn:x-option-value:edd_settings/purchase_receipt`, check the document for a Title and/or Header field, and then set related EDD options using them.

### Export Actions and Filters

In each of the below actions and filters, the `$md` argument is a `dirtsimple\Postmark\MarkdownFile` object, whose `body` is the `post_content` of the post being exported, and whose other properties will be exported as YAML frontmatter at the head of the document.  Both the actions and filters can read, set, or unset these properties as needed, thereby altering what will be written to the output file.

The hooks below are listed in execution order:

#### postmark_export_meta

`do_action('postmark_export_meta', $postmeta, MarkdownFile $md, WP_Post $post)` lets you modify the contents of  `$postmeta` (which will ultimately populate the `Post-Meta:` field of the exported document, if anything is left in it).  You can use this to unset meta values that would not be useful in the output, or set document fields from them.  (For example, postmark itself sets `$md->Template` from `$postmeta['_wp_page_template']` and then unsets it from `$postmeta`.)

The `$postmeta` object is a Bag (ArrayObject subclass with [extra methods](https://github.com/dirtsimple/imposer/blob/master/src/Bag.php)) that supports normal array operations like `$postmeta['foo']="bar"`, as well as methods like `has()`, `get()`, and `select()`.

#### postmark_export_meta_$key

`do_action("postmark_export_meta_$key", $meta_val, MarkdownFile $md, WP_Post $post)` runs on each meta field that's still in the `$postmeta` after running the `postmark_export_meta` hook.  `$meta_val` is the value of the field.

If any hooks are registered for this action, the corresponding `$key` will be removed from the `Post-Meta:` field of the exported document.  (This means that you can `add_action("postmark_export_meta_somekey", "__return_true");` as a trivial way to suppress a meta key from being exported (e.g. ones containing dynamic state that should not be saved in an export file.)

#### postmark_export

`do_action('postmark_export', MarkdownFile $md, WP_Post $post)` is called once for each export, with a fully-populated MarkdownFile object, before the output file is written.  You can use this to add, change, or remove fields as needed.  (For example, to add fields for a custom post type with data stored in other tables.)

#### postmark_export_slug

`apply_filters('postmark_export_slug', string $slug, MarkdownFile $md, WP_Post $post, $dir)` filters the slug that will be used to generate the export filename.  `$dir` is the output directory, and is either an empty string (for the current directory) or a directory name with a trailing `/`.  The initial value of `$slug` comes from `$md->Slug`, after the `postmark_export` hook has had the oppoturnity to change it.

#### postmark update wp-post

`do_action('postmark export wp-post', Bag $export, MarkdownFile $md, WP_Post $post, Document $doc)` is run by the `postmark update` command when updating an existing document (`$doc`) from its corresponding `$post`.  The `$md` variable contains the MarkdownFile object that would hav been written if a normal export were being performed, and `$export` is the ArrayObject whose contents will be written to the `.pmx.yml` file.  Callbacks registered for this action can modify `$export` to change what data will be written.

While most callbacks for this action will only need the first two arguments, the `$doc` argument can be used to look for flags to decide how to do the export.  For example, you could check `$doc->has('Export-Foo')` to decide whether to export certain data, similar to the built-in `Export-HTML` and `Export-Meta` fields.  Upon finding the flag in `$doc`, you would then copy the relevant data from `$md` (or `$post`) to a field on `$export`.

### Other Filters

#### postmark_author_email

`apply_filters('postmark_author_email', string $author, Document $doc)` filters the `Author:` front-matter field (if it exists) to extract an email or login which will be used to find the database ID of the post's author.  This filter accepts a string and should return an email, login, or WP_Error object.  If the string is already a valid email or login, the filter should return it unchanged.

This filter is only invoked if there is an `Author:` field in the front matter and `postmark_before_sync` didn't already set a `post_author`.  Internally, postmark treats the result of this as an Imposer `@wp-user` reference key, so technically, you can return anything that's recognized by Imposer as a `@wp-user` reference with no specific key type.

#### postmark_excluded_types

`apply_filters('postmark_excluded_types', array $post_types)` filters an array of post types that will *not* be importable by postmark.  By default, the array contains `['revision', 'edd_log', 'edd_payment', 'shop_order', 'shop_subscription']`, to exclude revisions and EDD/WooCommerce orders.  If you are using any plugins with custom post types that can grow to thousands of posts, but do not need to be imported, adding the post types to this list will help keep postmark's startup fast, by reducing the number of GUIDs that need to be loaded from the database.

## Project Status/Roadmap

This project is still in early development: tests are non-existent, and i18n of the CLI output is spotty.  Future features I hope to include are:

* Some way to mark a split point for excerpt extraction (preferably with link targeting from the excerpt to the break on the target page)
* Some way of handling images/attachments
* Link translation from relative file links to absolute URLs

See the full [roadmap/to-do here](https://github.com/dirtsimple/postmark/projects/1).