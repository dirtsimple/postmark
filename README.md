Sync Markdown Files to WordPress Posts and Pages
-------------------

Static site generators let you use a revision-controlled tree of markdown files to make a site, but don't offer a lot of themes or dynamic features.  Wordpress has lots of themes and dynamic features, but locks up your content in HTML embedded in a database, where you can't use your own editor or revision control anything.

So why not **combine** the two?

Postmark is a [wp-cli](https://wp-cli.org/) command that takes a markdown file (or entire tree of them) and creates or updates posts and pages in Wordpress.  Some key features include:

* No webserver configuration changes required: files can be synced from any directory on the server, and don't need to be writable or even readable by the web server.  (They do need to be readable by the user running the wp-cli command, though!)
* Files are synced using a GUID to identify the post or page in the DB, so the same files can be applied to multiple wordpress sites (e.g. dev/staging/prod, or common pages across a brand's sites), and moving or renaming a file changes its parent or slug in WP, instead of creating a new page or post.
* Files can contain YAML front matter to set all standard Wordpress page/post properties
* Custom post types are allowed, and plugins can use actions and filters to support custom fields or other WP data during sync.
* Plugins' special pages (like checkout or "my account") and themes' [custom CSS](#custom-css) can be synced using [Option References](#option-references), and plugins' HTML options can be synced using [Option Values](#option-values)
* [Prototypes and Templating](#prototypes-and-templating): In addition to their WP post type, files can have a `Prototype`, from which they inherit properties and an optional Twig template that can generate additional static content using data from the document's front-matter.
* Posts or pages are only updated if the size, timestamp, name, or location of an input file are changed (unless you use `--force`)
* Works great with almost any file-watching tool (like entr, gulp, modd, reflex, guard, etc.) to update edited posts as soon as you save them, with only the actually-changed files being updated even if your tool can't pass along the changed filenames.
* Markdown is converted using [league/commonmark](league/commonmark) with the [table](https://github.com/webuni/commonmark-table-extension#syntax) and [attribute](https://github.com/webuni/commonmark-attributes-extension#syntax) extensions, and you can add other extensions via filter.  Markdown content can include shortcodes, too.  (Though you may need to backslash-escape adjacent opening brackets to keep them from being treated as markdown footnote links.)
* Parent posts or pages are supported (nearest `index.md` above a file becomes its parent, recursively)
* Slugs default to the filename (or directory name for `index.md`), unless otherwise given
* Post/page titles default to the first line of the markdown body, if it's a heading

Postmark is similar in philosophy to [imposer](https://github.com/dirtsimple/imposer), in that synchronization is always one-way (from the filesystem to the database) but does not overwrite any database contents that aren't specified by the input file(s).  So any part of a post or page that's not in the markdown or YAML (such as comments) are unaffected by syncing.

Postmark is also similar to imposer in that it's pre-installed by [mantle](https://github.com/dirtsimple/mantle).  Mantle projects also have a  `script/watch` command that watches for changes to markdown files in the project's `content/` directory, and automatically syncs them to your development DB.

### Contents

<!-- toc -->

- [Overview](#overview)
  * [Installation and Use](#installation-and-use)
  * [File Format and Directory Layout](#file-format-and-directory-layout)
  * [The `ID:` Field](#the-id-field)
  * [Front Matter Fields](#front-matter-fields)
  * [Option URLs](#option-urls)
    + [Option References](#option-references)
    + [Custom CSS](#custom-css)
    + [Option Values](#option-values)
- [Prototypes and Templating](#prototypes-and-templating)
  * [Template Processing](#template-processing)
  * [Inheritance and Template Re-Use](#inheritance-and-template-re-use)
  * [Syncing Changes To Prototypes and Templates](#syncing-changes-to-prototypes-and-templates)
- [Imposer Integration](#imposer-integration)
- [Actions and Filters](#actions-and-filters)
  * [Markdown Formatting](#markdown-formatting)
  * [Document Objects and Sync](#document-objects-and-sync)
    + [postmark_before_sync](#postmark_before_sync)
    + [postmark_metadata](#postmark_metadata)
    + [postmark_content](#postmark_content)
    + [postmark_after_sync](#postmark_after_sync)
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

The `sync` command creates or updates posts or pages matching the given `.md` file(s), while the `tree` command processes all `.md` files within the named directories (and all their subdirectories).  The `--porcelain` option makes the output silent except for the Wordpress post/page IDs of the synced files.  (Which means you can get a file's Wordpress ID by passing its filename to `wp postmark sync --porcelain`.)

By default, posts and pages are not updated unless the `.md` file has changed (or been moved/renamed) since the last time it was synced, but `--force` overrides that and syncs all named files or directories, whether changed or not.  (This can be useful if you add or remove plugins that affect how posts are converted or formatted.)

To sync a markdown file with Wordpress, the file's front matter must include a globally unique identifier in the `ID` field.  If this value is missing, Postmark will add it automatically, unless you use the `--skip-create` option (in which case you'll get an error message instead).

To add an `ID`, Postmark must be able to write to both the file and the directory in question (to save a backup copy of the file during the change), so you should use `--skip-create` if those permissions are not available to the wp-cli user.

The other command Postmark provides is:

* `wp postmark uuid`

which takes no options and just outputs a UUID suitable for use as the `ID:` of a new `.md` file.  (See [The ID: Field](#the-id-field) below, for more info.)

### File Format and Directory Layout

Postmark expects to see markdown files with an `.md` extension and YAML front matter.  If a file is named `index.md`, it will become the Wordpress page/post parent of any other files in that directory or any subdirectory that don't contain its own `index.md`.  If not overridden in the YAML fields, the default slug of a post or page is its filename, minus the `.md` extension.  If the filename is `index.md`, the name of the containing directory is used instead.

When syncing any individual `.md` file, Postmark searches upward until a matching `index.md` file is found, or a "project root" directory is found.  (A project root is any directory containing a `.git`, `.hg`, `.svn`, or `.postmark` subdirectory.)  The first such `index.md` found becomes the parent page or post of the current `.md` file.  (And it is synced if it doesn't exist in the Wordpress database yet, searching recursively upward for more parents until every parent `index.md` exists and is made the parent of the corresponding child post or page.)

Postmark input files do not need to be placed under your Wordpress directory or even accessible by your webserver.  For security, they should not be *writable* by your webserver, and do not even need to be *readable* except by the user running `wp postmark` commands.  You also do not have to place all your markdown files in a single tree: the `postmark sync` and `postmark tree` commands accept multiple file and directory names, respectively.

Postmark input files use standard YAML front matter, delineated by `---` before and after, like this:

```markdown
---
ID: urn:uuid:1e30ea5f-17fe-422a-9c24-cb591eb2d72d
Draft: yes
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
Author:   foo@example.com    # user id is looked up by email address

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
```

Please note that Postmark only validates or converts a few of these fields.  Most are simply passed to Wordpress as-is, which may create problems if you use an invalid value.  (For example, if you assign a custom post type that isn't actually installed, or a status that the post type doesn't support.)  In most cases, however, you can fix such problems simply by changing the value to something valid and re-syncing the file.

Wordpress plugins or wp-cli packages can add extra fields (or change the handling of existing fields) by registering actions and filters.

### Option URLs

Option URLs are URLs that begin with either `urn:x-option-id:` (for an option reference) or `urn:x-option-value:` (for an option value), followed by a `/`-separated path that begins with a Wordpress option name.  By setting the `ID:` of your document to an option URL, you can cause the document's `post_id` or HTML content to be synced to the relevant subkey of that option.

The path portion of an option URL is essentially the same as the arguments given to `wp option pluck`, except that each argument is urlencoded and then joined with a `/`.  Each part of the path is either an array key or property name within the option value.  Any non-alphanumeric characters in any part of the path (other than `-` or `_`)  should be `%`-encoded, as failure to do so may produce errors or other undesirable results.

#### Option References

Many Wordpress plugins have special pages (like carts, checkouts, "my account", etc.) that are referenced in their settings as a post ID.  You can sync a markdown file to these pages using an `urn:x-option-id:` URL in the document's `ID:`, e.g.:

```yaml
ID: "urn:x-option-id:edd_settings/purchase_page"
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

#### Option Values

Some Wordpress plugins have options containing HTML content, that you might prefer to write using Markdown and/or maintain under revision control.  You can sync files to these settings using a `urn:x-option-value:` URL in each document's `ID:`, e.g.:

```yaml
ID: "urn:x-option-value:edd_settings/purchase_receipt"
```

A post with the above `ID:` will be synced by converting the document body to HTML, and then saving the result to the `purchase_receipt` key of the `edd_settings` option.  Most other front matter is ignored (except for that used by [prototypes and templating](#prototypes-and-templating)) and *no actual post is created*, so only the [markdown formatting](#markdown-formatting) hooks are invoked during the process, and no post ID will be output for the processed file.

Note that unlike regular posts/pages and option references (which can skip processing if the file hasn't changed and `--force` isn't used), the formatting and sync of an option value will *always* run, even if the file hasn't changed and `--force` is omitted.  (However, the option value in the database will only be changed if the HTML output is different from the value already present there.)

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

Then, in the same directory or a parent, create a `.postmark/` directory containing a `video.type.yml` file with the common properties:

```yaml
WP-Type: post
Draft: no
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

Templates can use full Twig syntax, including macros, the `extends` tag and `include()` function, which means that you can put other template files in your `.postmark` directory and then use them as partials or base templates, similar to other static site generators.  For example, above we could have done something like this:

```twig
{% from "macros.twig" import video_block %}
{{ body }}

{% for video in Videos %}
{{ video_block(video) }}
{% endfor %}
```

with a `macros.twig` in our `.postmark` directory containing:

```twig
{% macro video_block(video) %}
## {{ video.title }}
[video src="{{ video.url }}"]
{% endmacro %}
```

### Inheritance and Template Re-Use

A limited form of prototype inheritance is supported: if a prototype has a `.type.md` file with a `Prototype:`, then *that* prototype's properties are treated as defaults for the `.type.md`.  (Recursively, if the second prototype itself has a `Prototype:`).  Only properties are inherited, not templates, since applying a template to a template is unlikely to be useful.  If you need to share a template between multiple prototypes, put it in a separate `.twig` file, and then use Twig's `include()` (or `extends` or `import`) to apply it in each of the places where it's needed.

### Syncing Changes To Prototypes and Templates

Currently, postmark does not automatically re-sync unchanged documents whose prototype or template files have changed.  You can manually re-sync such documents using the `--force` option.  For convenience, you may wish to use a file-watching tool to do this automatically, e.g. via .[devkit](https://github.com/bashup/.devkit)'s [reflex-watch](https://github.com/bashup/.devkit#reflex-watch) module:

~~~shell
# Changes to content should be synced immediately, w/full sync at watch start
# and when templates or prototypes change

before "watch" wp postmark tree ./content
watch 'content/**/*.md' '!**/.postmark/*.md' -- wp postmark sync {}
watch 'content/.postmark/**' -- wp postmark tree ./content --force
~~~

The above .devkit configuration watches `./content` for changes to individual documents and runs `wp postmark sync` on the specific changed documents.  But if a file under `./content/.postmark` is changed, it resyncs the entire `./content` tree with `--force`, ensuring that all documents are up-to-date.

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

## Actions and Filters

### Markdown Formatting

Markdown formatting is controlled by the following filters:

* `apply_filters('postmark_formatter_config', array $cfg, Environment $env)` -- this filter is invoked once per command, to initialize the League/Commonmark [Environment](https://commonmark.thephpleague.com/customization/environment/) and [configuration](https://commonmark.thephpleague.com/configuration/).  Filters can add markdown extensions, parsers, or formatters to the `Environment` object, or return an altered `$cfg` array.  In addition to the standard configuration elements, `$cfg` contains an `extensions` array mapping extension class names to argument arrays (or null).  These extension classes are instantiated using the given argument arrays, and added to `$env`.

  The current default extensions are:

  * [`Webuni\CommonMark\TableExtension\TableExtension`](https://github.com/webuni/commonmark-table-extension#syntax), which implements Markdown tables,
  * [`Webuni\CommonMark\AttributesExtension\AttributesExtension`](https://github.com/webuni/commonmark-attributes-extension#syntax), which allows adding Kramdown-style HTML attributes to blocks and spans, and
  * [`OneMoreThing\CommonMark\Strikethrough\StrikethroughExtension`](https://github.com/omt/commonmark-strikethrough-extension/), which turns `~~`-wrapped text into `<del>` elements for strikethrough, and
  * [`League\CommonMark\Extras\SmartPunct\SmartPunctExtension`](https://github.com/thephpleague/commonmark-extras), which translates dots and hyphens to ellipses and em/en dashes, and converts plain single and double quotes to their left/right versions.

* `apply_filters('postmark_markdown', string $markdown, Document $doc, $fieldName)` -- this filter can alter the markdown content of a document (or any of its front-matter fields) before it's converted into HTML.  `$fieldName` is `"body"` if `$markdown` came from `$doc->body`; otherwise it is the name of the front matter field being converted.  (Such as `"Excerpt"`, or any custom fields added by plugins.)

* `apply_filters('postmark_html', string $html, Document $doc, $fieldName)` -- this filter can alter the HTML content of a document (or any of its front-matter fields) immediately after it's converted.  As with the `postmark_markdown` filter, `$fieldName` is either `"body"` or a front-matter field name.

Note that `postmark_markdown` and `postmark_html` may be invoked several times or not at all, as they are run whenever `$doc->html(...)` is called.  If a sync filter or action set `post_content` or `post_excerpt` before Postmark has a chance to, these filters won't be invoked unless the filter or action uses `$doc->html(...)` to do the conversion.

### Document Objects and Sync

Many filters and actions receive `dirtsimple\Postmark\Document` objects as a parameter.  These objects offer the following API:

* Front-matter fields are accessible as public, writable object properties.  (e.g. `$doc->Foo` returns front-matter field `Foo`) .  Fields that aren't valid PHP property names can be accessed using e.g. `$doc->{'Some-Field'}`.  Missing or empty fields return null; if you want a different default when the field is missing, you can use `$doc->meta('Somename', 'default-value')`.
* `$doc->exists()` returns truth if the document currently exists in Wordpress (as determined by looking up its `ID` as a Wordpress GUID)
* `$doc->body` is the markdown text of the document, and is a writable property.
* `$doc->html($propName='body')` converts the named property from Markdown to HTML (triggering the `postmark_markdown` and `postmark_html` filters).

During the sync process, a document builds up a `$postarr` array to be passed into `wp_insert_post` or `wp_update_post`.  Postmark only sets values in `$postarr` that have not already been set by an action or filter, so you can prevent it from doing so by setting a value first.

For example, Postmark calculates the `post_content` after calling the `postmark_metadata` filter, but before the `postmark_content` filter.  This means you can prevent Postmark from doing its own Markdown-to-HTML conversion by setting `post_content` from either the `postmark_before_sync` action, or the `postmark_metadata` filter.

The following actions and filters run during the sync process, in the following order:

#### postmark_before_sync

`do_action('postmark_before_sync', Document $doc)` allows modification of the document or other actions before it gets synced.  This action can set Wordpress post fields (e.g. `post_author `, `post_type`) in the `$doc->postinfo` array, to prevent Postmark from doing its default translations of those fields.  (The array is mostly empty at this point, however, so reading from it is not very useful.)  Setting `$doc->postinfo['wp_error']` to a WP_Error instance will force the sync to terminate with the given error.

(Note: `$doc->postinfo` should not be modified from any other action or filter, as changing it after this action will have no practical effect.  During the `postmark_after_sync` actions, its contents reflect the values *passed* to Wordpress, but may not reflect the actual post/page state since the insert or update may have triggered other plugins' actions and filters.)

#### postmark_metadata

`apply_filters('postmark_metadata', array $postarr, Document $doc)` filters the `$postarr` that will be passed to `wp_insert_post` or `wp_update_post`.  This filter can be used to override or extend the calculation of Wordpress fields based on the front matter.

When this filter runs, `$postarr` is initialized with any Wordpress field values that Postmark has calculated from the front matter,  or which were set in `$doc->postinfo` by `postmark_before_sync` actions.  It does *not*, however, contain the `post_content` or `post_excerpt` yet, unless set by a previous action or filter.

Functions registered for this filter can set the `post_content` or `post_excerpt` in `$postarr` to pre-empt Postmark from doing so.  They can also set `$postarr['wp_error']` to a WP_Error object to terminate the sync process with an error.

If `post_content`, `post_title`, `post_excerpt`, or `post_status` remain empty after processing all functions registered for this filter, Postmark will supply default values by converting the document body from Markdown to HTML, and/or extracting a title and excerpt as needed.

#### postmark_content

`apply_filters('postmark_content', array $postarr, Document $doc)` is similar to the `postmark_metadata` filter, except that markdown conversion and title/excerpt extraction have already been done, if needed. 

#### postmark_after_sync

`do_action('postmark_after_sync', Document $doc, WP_Post $rawPost)` allows post-sync actions to be run on the document and/or resulting post.  `$rawPost` is a `raw`-filtered Wordpress WP_Post object, reflecting the now-synced post.  This can be used to process front matter fields that require the post ID to be known (e.g. adding data to custom tables).

### Other Filters

#### postmark_author_email

`apply_filters('postmark_author_email', string $author, Document $doc)` filters the `Author:` front-matter field (if it exists) to extract an email which will be used to get the user ID of the post's author.  This filter accepts a string and should return an email or WP_Error object.  If the string is already a valid email, the filter should return it unchanged.

This filter is only invoked if there is an `Author:` field in the front matter and `postmark_before_sync` didn't already set a `post_author`.

#### postmark_excluded_types

`apply_filters('postmark_excluded_types', array $post_types)` filters an array of post types that will *not* be importable by postmark.  By default, the array contains `['revision', 'edd_payment', 'shop_order', 'shop_subscription']`, to exclude revisions and EDD/WooCommerce orders.  If you are using any plugins with custom post types that can grow to thousands of posts, but do not need to be imported, adding the post types to this list will help keep postmark's startup fast, by reducing the number of GUIDs that need to be loaded from the database.

## Project Status/Roadmap

This project is still in early development: tests are non-existent, and i18n of the CLI output is spotty.  Future features I hope to include are:

* Exporting existing posts or pages
* Some way to mark a split point for excerpt extraction (preferably with link targeting from the excerpt to the break on the target page)
* Some way of handling images/attachments
* Link translation from relative file links to absolute URLs'

See the full [roadmap/to-do here](https://github.com/dirtsimple/postmark/projects/1).