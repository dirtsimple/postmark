<?php
namespace dirtsimple\Postmark;

use dirtsimple\imposer\Bag;
use WP_Error;

class PostExporter {

	/* Markdown file for a post being exported */

	protected $__post;

	static function export_post(MarkdownFile $mdf, $id, $dir, $doc=null) {
		if ( ! $id || ! $post = get_post($id) ) return false;
		if ( is_wp_error($post) ) return $post;

		$id = $post->ID;
		$mdf->body = $post->post_content;

		$mdf->ID = $post->guid;
		$mdf->Title = $post->post_title;
		$mdf->Slug = $post->post_name;

		if ( $user = get_user_by('id', $post->post_author) )
			$mdf->Author = $user->user_email;

		$mdf->Date = $post->post_date_gmt . " UTC";
		$mdf->Updated = $post->post_modified_gmt . " UTC";

		$mdf->Excerpt = $post->post_excerpt;

		$mdf->{'WP-Type'} = $post->post_type;

		$status = $post->post_status;
		if ($status === 'publish' || $status === 'draft') {
			$mdf->Draft = ($status === 'draft');
		} else {
			$mdf->Status = $status;
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
			$mdf->Category = $terms['category'];
			unset( $terms['category'] );
		}
		if ( isset( $terms['post_tag'] ) ) {
			$mdf->Tags = $terms['post_tag'];
			unset( $terms['post_tag'] );
		}
		if ( $terms ) $mdf->{'WP-Terms'} = $terms;

		$mdf->Password = $post->post_password;
		$mdf->Comments = $post->comment_status;
		$mdf->Pings    = $post->ping_status;
		if ( isset($post->page_template) ) $mdf->Template = $post->page_template;

		$meta = array_reduce(
			array_keys( get_post_meta($id) ),
			function ($m, $k) use ($id) { $m[$k] = get_post_meta($id, $k, true); return $m; },
			array()
		);
		unset( $meta['_wp_page_template'], $meta['_edit_last'], $meta['_edit_lock'], $meta['_thumbnail_id'] );
		unset( $meta['_postmark_cache'] );
		$meta = new Bag($meta);
		do_action('postmark_export_meta', $meta, $mdf, $post);

		foreach ($meta->items() as $key=>$val) {
			if ( has_action("postmark_export_meta_$key") ) {
				do_action("postmark_export_meta_$key", $val, $mdf, $post);
				unset($meta[$key]);
			}
		}

		if ( $meta->count() ) $mdf['Post-Meta'] = $meta->items();

		if ( $doc ) {
			$data = array();
			foreach ( $doc->get('Export-Meta', array()) as $k => $v ) {
				if ( $v !== false ) $data[$k] = $meta->get($k);
			}
			$mdf->exchangeArray( $data ? array('Post-Meta'=>$data) : array() );
		} else {
			do_action('postmark_export', $mdf, $post);
			return apply_filters('postmark_export_slug', $mdf->Slug, $mdf, $post, $dir);
		}
	}
}
