<?php
namespace dirtsimple\Postmark;
use Mustangostang\Spyc;

class Prototype {

	protected $root, $files, $mdfile=null;

	function __construct($root, $files) {
		$this->root = $root;
		$this->files = $files;
	}

	function apply_to($doc) {
		$mdfile = $this->mdfile ?: $this->load();

		$doc->inherit( $mdfile->meta() );
		if ( $doc->is_template ) return;

		if ( ! empty($tpl = $this->mdfile->body) ) {
			$doc->body = $this->root->render($doc, $this->files['md'], $tpl);
		}

		if ( isset( $this->files['twig'] ) ) {
			$doc->body = $this->root->render($doc, $this->files['twig']);
		}
	}

	function load() {
		$this->mdfile = new MarkdownFile();

		if ( isset( $this->files['yml'] ) ) {
			$this->mdfile->inherit( Spyc::YAMLLoad( $this->files['yml'] ) );
		}

		if ( isset( $this->files['md'] ) ) {
			$type = Project::doc( $this->files['md'], true );
			$this->mdfile->inherit( $type->meta() );
			if ( ! empty(trim($tpl = $type->unfence('twig'))) ) {
				$this->mdfile->body = $tpl;
				$this->files['md'] = Project::basename($this->files['md']);
			}
		}

		if ( isset( $this->files['twig'] ) ) {
			$this->files['twig'] = Project::basename($this->files['twig']);
		}

		return $this->mdfile;
	}

}
