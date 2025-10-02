<?php

class WWW_Table_Of_Products_Contents extends WWW_Table_Of_Contents {

    public function run() {
        add_filter('the_content', array($this, 'www_insert_table_of_contents_into_products'), 10);
    }

    public function www_insert_table_of_contents_into_products($content) {
        if (is_singular('product')) {
            global $product;
            $short_description = $product->get_short_description();

            // Aggiungi ID univoci agli elementi h3 nella descrizione lunga
            $content = preg_replace_callback('/<h3>/', function ($matches) {
                static $count = 0;
                return '<h3 id="www-toc-' . ++$count . '">';
            }, $content);

            $toc = $this->www_shortcode_table_of_contents([], $content);

            // Posiziona la TOC dove presente lo shortcode o in cima alla descrizione lunga
            if (has_shortcode($content, 'www-toc')) {
                $content = str_replace('[www-toc]', $toc, $content);
            } else {
                $content = preg_replace('/(<h[2-5]{1}[^<>]*>)/', $toc . '$1', $content, 1);
            }

            $content = $short_description . $content; // Include short description and main content
        }
        return $content;
    }

    public function www_generate_product_json_ld() {
        global $product;

        $json_ld = [
            '@type' => "Product",
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'url' => get_permalink($product->get_id()),
            'image' => wp_get_attachment_url($product->get_image_id()),
            'price' => $product->get_price(),
            'availability' => $product->is_in_stock() ? 'InStock' : 'OutOfStock',
            'sku' => $product->get_sku(), // Add the SKU
            'gtin' => $product->get_meta('gtin') // Add the GTIN
        ];

        return '<script type="application/ld+json">' . json_encode($json_ld) . '</script>';
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

        // Add the JSON-LD objects to the head
        add_action('wp_head', function() use ($table_of_contents) {
            echo $this->www_generate_product_json_ld(); // Dati strutturati del prodotto
            echo parent::www_generate_json_ld($table_of_contents); // Dati strutturati della TOC
        });

        // Apply filters for customization and sanitize the final HTML
        $html = wp_kses_post(apply_filters('www_shortcode_table_of_contents', $html, $atts));
        return $html;
    }
}