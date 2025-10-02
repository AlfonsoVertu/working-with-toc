<?php
/**
 * Heading parser utility.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parse headings and ensure IDs.
 */
class Heading_Parser {

    /**
     * Parse content and return headings plus updated content.
     *
     * @param string $content HTML content.
     *
     * @return array{headings:array<int,array{title:string,id:string,level:int}>,content:string}
     */
    public static function parse( string $content ): array {
        if ( ! class_exists( \DOMDocument::class ) || is_domdocument_missing() ) {
            return array(
                'headings' => array(),
                'content'  => $content,
            );
        }

        $dom       = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8"?>' . $content );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $xpath    = new DOMXPath( $dom );
        $nodes    = $xpath->query( '//h2 | //h3 | //h4 | //h5 | //h6' );
        $headings = array();
        $used_ids = array();

        if ( $nodes ) {
            /** @var DOMElement $node */
            foreach ( $nodes as $node ) {
                $text = trim( wp_strip_all_tags( $dom->saveHTML( $node ) ) );
                if ( '' === $text ) {
                    continue;
                }

                $id = $node->getAttribute( 'id' );
                if ( ! $id ) {
                    $id = sanitize_title( $text );
                }

                $original_id = $id;
                $i           = 2;
                while ( in_array( $id, $used_ids, true ) ) {
                    $id = $original_id . '-' . $i;
                    $i++;
                }

                $used_ids[] = $id;
                $node->setAttribute( 'id', $id );

                $headings[] = array(
                    'title' => $text,
                    'id'    => $id,
                    'level' => (int) substr( $node->nodeName, 1 ),
                );
            }
        }

        $body    = $dom->getElementsByTagName( 'body' )->item( 0 );
        $updated = $content;

        if ( $body ) {
            $updated = '';
            foreach ( $body->childNodes as $child ) {
                $updated .= $dom->saveHTML( $child );
            }
        }

        return array(
            'headings' => $headings,
            'content'  => $updated,
        );
    }
}
