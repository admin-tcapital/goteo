<?php

$this->layout('blog/layout', [
    'title' => $this->post->title,
    'meta_description' => $this->post->title
    ]);

$this->section('blog-content');

?>

	<?= $this->insert('blog/partials/blog_header') ?>


<?php $this->replace() ?>