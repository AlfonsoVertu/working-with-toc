<?php

class WWW_Table_Of_Contents {

    public function run() {
        add_shortcode('www-toc', array($this, 'www_shortcode_table_of_contents'));
        add_filter('the_content', array($this, 'www_table_of_contents_section_anchors'), 10);
    }

    public function www_shortcode_table_of_contents($atts, $content = null) {
        global $post;
        $table_of_contents = $this->www_get_table_of_contents($post->post_content);
        $html = '';

        if (!empty($table_of_contents['list'])) {
            $html = '<div>';
            $html .= '<ul class="www-toc-ul">' . implode('', $table_of_contents['list']) . '</ul>' . "\n";
            $html .= '</div>';
        }

        // Add the JSON-LD object to the head
        add_action('wp_head', function() use ($table_of_contents) {
            echo $this->www_generate_json_ld($table_of_contents);
        });

        // Apply filters for customization and sanitize the final HTML
        $html = wp_kses_post(apply_filters('www_shortcode_table_of_contents', $html, $atts));
        return $html;
    }

    public function www_table_of_contents_section_anchors($content) {
        if (is_singular('post')) {
            $data = $this->www_get_table_of_contents($content);
            foreach ($data['sections_with_ids'] as $k => $v) {
                $content = str_replace($data['sections'][$k], $v, $content);
            }

            if (!has_shortcode($content, 'www-toc')) {
                $toc = $this->www_shortcode_table_of_contents([], $content);
                $content = preg_replace('/(<h[2-5]{1}[^<>]*>)/', $toc . '$1', $content, 1);
            }
        }
        // Sanitize the final content
        return wp_kses_post($content);
    }

    public function www_get_table_of_contents($content) {
        preg_match_all("/(<h([2-5]{1})[^<>]*>)([^<>]+)(<\/h[2-5]{1}>)/", $content, $matches, PREG_SET_ORDER);
        $count = 0;
        $list = array();
        $sections = array();
        $sections_with_ids = array();

        foreach ($matches as $val) {
            $count++;
            $id = 'www-toc-' . $count;
            $title = esc_attr($val[3]);
            $list[$count] = '<li class="www-toc-li" title="' . $title . '"><a href="#' . esc_attr($id) . '" title="' . $title . '" class="www-toc-a">' . esc_html($val[3]) . '</a></li>';
            $sections[$count] = $val[1] . $val[3] . $val[4];
            $sections_with_ids[$count] = '<h' . $val[2] . ' id="' . esc_attr($id) . '" class="www-h' . $val[2] . ' www-title">' . esc_html($val[3]) . $val[4];
        }
        return array('list' => $list, 'sections' => $sections, 'sections_with_ids' => $sections_with_ids);
    }

    public function www_generate_json_ld($table_of_contents) {
        $json_ld = array(
            "@context" => "https://schema.org",
            "@type" => "TableOfContents",
            "name" => "Table of Contents",
            "description" => "Table of contents for the post",
            "url" => get_permalink(),
        );

        // Add the image if available
        if (has_post_thumbnail()) {
            $image = wp_get_attachment_image_src(get_post_thumbnail_id(), 'full');
            if ($image) {
                $json_ld['image'] = array(
                    "@type" => "ImageObject",
                    "url" => esc_url($image[0]),
                    "width" => $image[1],
                    "height" => $image[2]
                );
            }
        }

        return '<script type="application/ld+json">' . json_encode($json_ld) . '</script>';
    }
}
// Instantiate the plugin class
new WWW_Table_Of_Contents();