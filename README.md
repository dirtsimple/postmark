Sync Markdown Files to WordPress Posts and Pages
-------------------

Static site generators let you can use a revision-controlled tree of markdown files to make a site, but don't offer a lot of themes or dynamic features.  Wordpress has lots of themes and dynamic features, but locks up your content in HTML embedded in a database, where you can't use your own editor or revision control anything.

So why not **combine** the two?

Postmark is a [wp-cli](https://wp-cli.org/) command that takes a markdown file (or entire tree of them) and creates or updates posts and pages in Wordpress.  Some key features include:

* No webserver configuration changes required: files can be synced from any directory on the server, and don't need to be writable or even readable by the web server.  (They do need to be readable by the user running the wp-cli command, though!)
* Files are synced using a GUID to identify the post or page in the DB, so the same files can be applied to multiple wordpress sites (e.g. dev/staging/prod, or common pages across a brand's sites), and moving or renaming a file changes its parent or slug in WP, instead of creating a new page or post.
* Files can contain YAML front matter to set all standard Wordpress page/post properties
* Custom post types are allowed, and plugins can use actions and filters to support custom fields or other WP data during sync
* Posts or pages are only updated if the size, timestamp, name, or location of an input file are changed (unless you use `--force`)
* Works great with almost any file-watching tool (like entr, gulp, modd, reflex, guard, etc.) to update edited posts as soon as you save them, with only the actually-changed files being updated even if your tool can't pass along the changed filenames.
* Markdown is converted using [league/commonmark](league/commonmark) with the [table](https://github.com/webuni/commonmark-table-extension#syntax) and [attribute](https://github.com/webuni/commonmark-attributes-extension#syntax) extensions, and you can add other extensions via filter.  Markdown content can include shortcodes, too.  (Though you may need to backslash-escape adjacent opening brackets to keep them from being treated as markdown footnote links.)
* Parent posts or pages are supported (nearest `index.md` above a file becomes its parent, recursively)
* Slugs default to the filename (or directory name for `index.md`), unless otherwise given
* Post/page titles default to the first line of the markdown body, if it's a heading

Postmark is similar in philosophy to [imposer](https://github.com/dirtsimple/imposer), in that synchronization is always one-way (from the filesystem to the database) but does not overwrite any database contents that aren't specified by the input file(s).  So any part of a post or page that's not in the markdown or YAML (such as comments) are unaffected by syncing.

Also like imposer, postmark is bundled with [mantle](https://github.com/dirtsimple/mantle), and when you run mantle's `script/watch` command, posts are automatically synced to your development DB whenever you save their `.md` files.

### Contents

<!-- toc -->

- [Overview](#overview)
  * [Installation and Use](#installation-and-use)
  * [File Format and Directory Layout](#file-format-and-directory-layout)
  * [The `ID:` Field](#the-id-field)
  * [Front Matter Fields](#front-matter-fields)
- [Actions and Filters](#actions-and-filters)
  * [Markdown Formatting](#markdown-formatting)
  * [Document Objects and Sync](#document-objects-and-sync)
    + [postmark_before_sync](#postmark_before_sync)
    + [postmark_metadata](#postmark_metadata)
    + [postmark_content](#postmark_content)
    + [postmark_after_sync](#postmark_after_sync)
  * [Other Filters](#other-filters)
    + [postmark_author_email](#postmark_author_email)
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

## Actions and Filters

### Markdown Formatting

Markdown formatting is controlled by the following filters:

* `apply_filters('postmark_formatter_config', array $cfg, Environment $env)` -- this filter is invoked once per command, to initialize the League/Commonmark [Environment](https://commonmark.thephpleague.com/customization/environment/) and [configuration](https://commonmark.thephpleague.com/configuration/).  Filters can add markdown extensions, parsers, or formatters to the `Environment` object, or return an altered `$cfg` array.  In addition to the standard configuration elements, `$cfg` contains an `extensions` array mapping extension class names to argument arrays (or null).  These extension classes are instantiated using the given argument arrays, and added to `$env`.

  The current default extensions are:

  * [`Webuni\CommonMark\TableExtension\TableExtension`](https://github.com/webuni/commonmark-table-extension#syntax), which implements Markdown tables, and
  * [`Webuni\CommonMark\AttributesExtension\AttributesExtension`](https://github.com/webuni/commonmark-attributes-extension#syntax), which allows adding Kramdown-style HTML attributes to blocks and spans.

* `apply_filters('postmark_markdown', string $markdown, Document $doc, $fieldName)` -- this filter can alter the markdown content of a document (or any of its front-matter fields) before it's converted into HTML.  `$fieldName` is `"body"` if `$markdown` came from `$doc->body`; otherwise it is the name of the front matter field being converted.  (Such as `"Excerpt"`, or any custom fields added by plugins.)

* `apply_filters('postmark_html', string $html, Document $doc, $fieldName)` -- this filter can alter the HTML content of a document (or any of its front-matter fields) immediately after it's converted.  As with the `postmark_markdown` filter, `$fieldName` is either `"body"` or a front-matter field name.

Note that `postmark_markdown` and `postmark_html` may be invoked several times or not at all, as they are run whenever `$doc->html(...)` is called.  If a sync filter or action set `post_content` or `post_excerpt` before Postmark has a chance to, these filters won't be invoked unless the filter or action uses `$doc->html(...)` to do the conversion.

### Document Objects and Sync

Many filters and actions receive `dsi\Postmark\Document` objects as a parameter.  These objects offer the following API:

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

## Project Status/Roadmap

This project is still in early development: tests are non-existent, and i18n of the CLI output is spotty.  Future features I hope to include are:

* Bundled support for strikethrough (`~~`)
* Templates or prototypes for creating posts of a particular type, either creating the markdown file or as DB defaults
* Integration with [imposer](https://github.com/dirtsimple/imposer)
* Exporting existing posts or pages
* Some way to mark a split point for excerpt extraction (preferably with link targeting from the excerpt to the break on the target page)