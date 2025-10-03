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
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Parse headings and ensure IDs.
 */
class Heading_Parser {

    /**
     * Parse content and return headings plus updated content.
     *
     * @param string $content HTML content.
     * @param array  $options Parsing options.
     *
     * @return array{headings:array<int,array{title:string,id:string,level:int,faq_excerpt?:string}>,content:string}
     */
    public static function parse( string $content, array $options = array() ): array {
        if ( ! class_exists( \DOMDocument::class ) || is_domdocument_missing() ) {
            return array(
                'headings' => array(),
                'content'  => $content,
            );
        }

        $faq_ids = array();
        if ( isset( $options['faq_ids'] ) && is_array( $options['faq_ids'] ) ) {
            foreach ( $options['faq_ids'] as $raw_id ) {
                if ( ! is_string( $raw_id ) ) {
                    continue;
                }

                $sanitised = sanitize_text_field( $raw_id );
                if ( '' === $sanitised ) {
                    continue;
                }

                $faq_ids[ $sanitised ] = true;
            }
        }

        $mark_faq         = ! empty( $options['mark_faq'] ) && ! empty( $faq_ids );
        $mark_faq_answers = $mark_faq && ( ! isset( $options['mark_faq_answers'] ) || $options['mark_faq_answers'] );
        $extract_faq      = ! empty( $options['extract_faq'] ) || $mark_faq;

        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8"?>' . $content );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $xpath    = new DOMXPath( $dom );
        $nodes    = $xpath->query( '//h2 | //h3 | //h4 | //h5 | //h6' );
        $headings = array();
        $used_ids = array();

        if ( $nodes ) {
            $length = $nodes->length;

            for ( $index = 0; $index < $length; $index++ ) {
                $node = $nodes->item( $index );

                if ( ! $node instanceof DOMElement ) {
                    continue;
                }

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

                $faq_data = array(
                    'excerpt'     => '',
                    'answer_node' => null,
                );

                if ( $extract_faq ) {
                    $next_heading = null;

                    if ( $index + 1 < $length ) {
                        $candidate = $nodes->item( $index + 1 );
                        if ( $candidate instanceof DOMElement ) {
                            $next_heading = $candidate;
                        }
                    }

                    $faq_data = self::extract_faq_data( $node, $next_heading );
                }

                if ( $mark_faq && isset( $faq_ids[ $id ] ) ) {
                    self::add_class( $node, 'wwt-faq-question' );
                    $node->setAttribute( 'data-wwt-faq', 'question' );

                    if ( $mark_faq_answers && $faq_data['answer_node'] instanceof DOMElement ) {
                        $answer_node = $faq_data['answer_node'];

                        if ( 'body' !== strtolower( $answer_node->nodeName ) ) {
                            self::add_class( $answer_node, 'wwt-faq-answer' );

                            if ( '' === $answer_node->getAttribute( 'data-wwt-faq' ) ) {
                                $answer_node->setAttribute( 'data-wwt-faq', 'answer' );
                            }
                        }
                    }
                }

                $headings[] = array(
                    'title'       => $text,
                    'id'          => $id,
                    'level'       => (int) substr( $node->nodeName, 1 ),
                    'faq_excerpt' => $faq_data['excerpt'],
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

    /**
     * Extract the FAQ excerpt and related node from the DOM.
     *
     * @param DOMElement      $heading      Heading node currently being processed.
     * @param DOMElement|null $next_heading Next heading node in document order.
     *
     * @return array{excerpt:string,answer_node:DOMElement|null}
     */
    protected static function extract_faq_data( DOMElement $heading, ?DOMElement $next_heading ): array {
        $text        = '';
        $answer_node = null;

        $current = $heading;

        while ( null !== ( $current = self::get_next_node( $current ) ) ) {
            if ( $next_heading instanceof DOMElement && $current instanceof DOMNode && $current->isSameNode( $next_heading ) ) {
                break;
            }

            if ( $current instanceof DOMElement ) {
                if ( self::is_heading( $current ) ) {
                    break;
                }

                $tag = strtolower( $current->nodeName );
                if ( in_array( $tag, array( 'script', 'style' ), true ) ) {
                    continue;
                }

                if ( null === $answer_node && ! self::is_descendant_of( $current, $heading ) ) {
                    $candidate_text = trim( wp_strip_all_tags( $current->textContent ?? '' ) );
                    if ( '' !== $candidate_text ) {
                        $answer_node = $current;
                    }
                }
            }

            if ( $current instanceof DOMText ) {
                $parent = $current->parentNode;

                if ( $parent instanceof DOMElement ) {
                    $parent_tag = strtolower( $parent->nodeName );

                    if ( in_array( $parent_tag, array( 'script', 'style' ), true ) ) {
                        continue;
                    }
                }

                if ( self::is_descendant_of( $current, $heading ) ) {
                    continue;
                }

                $text .= ' ' . $current->wholeText;

                if ( null === $answer_node && $parent instanceof DOMElement && 'body' !== strtolower( $parent->nodeName ) ) {
                    $parent_text = trim( wp_strip_all_tags( $parent->textContent ?? '' ) );
                    if ( '' !== $parent_text ) {
                        $answer_node = $parent;
                    }
                }
            }
        }

        return array(
            'excerpt'     => self::prepare_excerpt( $text ),
            'answer_node' => $answer_node instanceof DOMElement ? $answer_node : null,
        );
    }

    /**
     * Normalise an excerpt string limiting its length.
     */
    protected static function prepare_excerpt( string $text ): string {
        if ( '' === $text ) {
            return '';
        }

        $text = wp_strip_all_tags( $text );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );

        if ( '' === $text ) {
            return '';
        }

        if ( function_exists( 'mb_substr' ) ) {
            $text = trim( mb_substr( $text, 0, 50 ) );
        } else {
            $text = trim( substr( $text, 0, 50 ) );
        }

        return $text;
    }

    /**
     * Retrieve the next node in document order.
     */
    protected static function get_next_node( DOMNode $node ): ?DOMNode {
        if ( $node->firstChild ) {
            return $node->firstChild;
        }

        while ( $node ) {
            if ( $node->nextSibling ) {
                return $node->nextSibling;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * Determine if a node is one of the supported heading tags.
     */
    protected static function is_heading( DOMElement $element ): bool {
        $tag = strtolower( $element->nodeName );

        return in_array( $tag, array( 'h2', 'h3', 'h4', 'h5', 'h6' ), true );
    }

    /**
     * Check whether the given node is a descendant of the provided ancestor.
     */
    protected static function is_descendant_of( DOMNode $node, DOMNode $ancestor ): bool {
        $parent = $node->parentNode;

        while ( $parent ) {
            if ( $parent->isSameNode( $ancestor ) ) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * Append a CSS class to an element.
     */
    protected static function add_class( DOMElement $element, string $class ): void {
        $existing = $element->getAttribute( 'class' );
        $classes  = array();

        if ( '' !== $existing ) {
            $classes = preg_split( '/\s+/', $existing, -1, PREG_SPLIT_NO_EMPTY );
            if ( ! is_array( $classes ) ) {
                $classes = array();
            }
        }

        $classes[] = $class;
        $classes   = array_values( array_unique( array_filter( $classes ) ) );

        if ( empty( $classes ) ) {
            $element->removeAttribute( 'class' );

            return;
        }

        $element->setAttribute( 'class', implode( ' ', $classes ) );
    }
}
