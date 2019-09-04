<?php
namespace dirtsimple\Postmark;

class Prototype {

	protected $root, $files, $mdfile=null;

	function __construct($root, $files) {
		$this->root = $root;
		$this->files = $files;
	}

	function apply_to($doc) {
		$doc->inherit( $this->meta() );

		if ( ! empty($tpl = $this->mdfile->body) ) {
			$doc->body = $this->root->render($doc, $this->files['md'], $tpl);
		}

		if ( isset( $this->files['twig'] ) ) {
			$doc->body = $this->root->render($doc, $this->files['twig']);
		}
	}

	function meta() { return $this->load()->meta();	}

	protected function load() {

		if ( isset($this->mdfile) ) return $this->mdfile;

		$this->mdfile = new MarkdownFile();

		if ( isset( $this->files['yml'] ) ) {
			$this->mdfile->inherit( Yaml::parseFile( $this->files['yml'] ) );
		}

		if ( isset( $this->files['md'] ) ) {

			$filename = $this->files['md'];
			$type = MarkdownFile::fromFile($filename);

			if ( $super = $type->get('Prototype') ) {
				$type->inherit( $this->root->prototype($super, $filename)->meta() );
			}

			$this->mdfile->inherit( $type->meta() );

			if ( ! empty(trim($tpl = $type->unfence('twig'))) ) {
				$this->mdfile->body = $tpl;
				$this->files['md'] = Project::basename($filename);
			}
		}

		if ( isset( $this->files['twig'] ) ) {
			$this->files['twig'] = Project::basename($this->files['twig']);
		}

		return $this->mdfile;
	}

}
