<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Bag;
use WP_Error;

class ExportFile extends MarkdownFile {

	/* Markdown file for a post being exported */

	protected $__post;

	function __construct($post, $guid=null) {
		$this->__post = $post;

		$id = $post->ID;
		$this->body = $post->post_content;

		$this->ID = $guid ?: $post->guid;
		$this->Title = $post->post_title;
		$this->Slug = $post->post_name;

		$this->Author = get_user_by('id', $post->post_author)->user_email;
		$this->Date = $post->post_date_gmt . " UTC";
		$this->Updated = $post->post_modified_gmt . " UTC";

		$this->Excerpt = $post->post_excerpt;

		$this->{'WP-Type'} = $post->post_type;

		$status = $post->post_status;
		if ($status === 'publish' || $status === 'draft') {
			$this->Draft = ($status === 'draft');
		} else {
			$this->Status = $status;
		}

		$terms = array_reduce(
			wp_get_object_terms( array($id), get_taxonomies() ),
			function ($terms, $term) {
				$terms[$term->taxonomy][]=$term->name;
				return $terms;
			},
			array()
		);
		if ( isset( $terms['category'] ) ) {
			$this->Category = $terms['category'];
			unset( $terms['category'] );
		}
		if ( isset( $terms['post_tag'] ) ) {
			$this->Tags = $terms['post_tag'];
			unset( $terms['post_tag'] );
		}
		if ( $terms ) $this->{'WP-Terms'} = $terms;

		$this->Password = $post->post_password;
		$this->Comments = $post->comment_status;
		$this->Pings    = $post->ping_status;
		if ( isset($post->page_template) ) $this->Template = $post->page_template;

		$meta = array_reduce(
			array_keys( get_post_meta($id) ),
			function ($m, $k) use ($id) { $m[$k] = get_post_meta($id, $k, true); return $m; },
			array()
		);
		unset( $meta['_wp_page_template'], $meta['_edit_last'], $meta['_edit_lock'], $meta['_thumbnail_id'] );
		unset( $meta['_postmark_cache'] );
		$meta = new Bag($meta);
		do_action('postmark_export_meta', $meta, $this, $post);
		$this->{'Post-Meta'} = $meta->items();

		do_action('postmark_export', $this, $post);
	}

	function exportTo($dir='') {
		$slug = apply_filters('postmark_export_slug', $this->Slug, $this, $this->__post, $dir);
		$suff = '';
		while (
			file_exists($fn = "$dir$slug$suff.md") &&
			( MarkdownFile::fromFile($fn)->ID != $this->ID )
		) $suff--;
		$res = file_exists($fn) ? $this->saveAs($fn) : $this->dump($fn);
		return $res ? $fn : false;
	}
}
