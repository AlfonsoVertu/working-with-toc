#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);

define('ABSPATH', $root . '/');

// Minimal WordPress stubs required by the classes we load during linting.
if (!function_exists('__')) {
    function __(string $text, ?string $domain = null): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        return $text;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class, $fallback = '') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $class);
        if ($sanitized === '') {
            $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $fallback);
        }
        return $sanitized;
    }
}
if (!function_exists('absint')) {
    function absint($maybeint) {
        return (int) max(0, (int) $maybeint);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_string($str) ? trim($str) : '';
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return is_string($url) ? trim($url) : '';
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        return 'https://example.com/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        return 'Example Site';
    }
}
if (!function_exists('get_site_icon_url')) {
    function get_site_icon_url() {
        return '';
    }
}
if (!function_exists('is_amp_endpoint')) {
    function is_amp_endpoint(): bool {
        return false;
    }
}
if (!function_exists('amp_is_request')) {
    function amp_is_request(): bool {
        return false;
    }
}

require_once $root . '/includes/class-settings.php';
require_once $root . '/includes/frontend/class-frontend.php';

use Working_With_TOC\Frontend\Frontend;
use Working_With_TOC\Settings;

$cssPath = $root . '/assets/css/frontend-amp.css';
if (!is_file($cssPath)) {
    fwrite(STDERR, "Missing AMP stylesheet at {$cssPath}\n");
    exit(1);
}

$css = file_get_contents($cssPath);
if ($css === false) {
    fwrite(STDERR, "Unable to read AMP stylesheet.\n");
    exit(1);
}

$cssViolations = [];
if (strpos($css, '!important') !== false) {
    $cssViolations[] = 'Custom AMP stylesheet must not contain !important declarations.';
}
if (preg_match('/@import\b/i', $css)) {
    $cssViolations[] = 'Custom AMP stylesheet must not use @import.';
}
if (strlen($css) > 75000) {
    $cssViolations[] = 'Custom AMP stylesheet exceeds the 75KB limit imposed by AMP.';
}

if ($cssViolations) {
    fwrite(STDERR, "AMP CSS validation failed:\n - " . implode("\n - ", $cssViolations) . "\n");
    exit(1);
}

$settings = new Settings();

class AmpTestFrontend extends Frontend {
    protected function is_amp_request(): bool {
        return true;
    }
}

$frontend = new AmpTestFrontend($settings);

$headings = [
    ['title' => 'Introduzione', 'id' => 'introduzione', 'level' => 2],
    ['title' => 'Approfondimento', 'id' => 'approfondimento', 'level' => 3],
];

$preferences = [
    'title'                  => 'Indice dei contenuti',
    'background_color'       => '#ffffff',
    'text_color'             => '#111827',
    'link_color'             => '#2563eb',
    'title_background_color' => '#1f2937',
    'title_color'            => '#ffffff',
    'horizontal_alignment'   => 'center',
    'vertical_alignment'     => 'top',
    'has_custom_title_colors' => true,
];

$reflection = new ReflectionClass(Frontend::class);
$method     = $reflection->getMethod('build_toc_markup');
$method->setAccessible(true);
$markup = (string) $method->invoke($frontend, $headings, $preferences, 42, true);

if ($markup === '') {
    fwrite(STDERR, "Failed to build AMP TOC markup for validation.\n");
    exit(1);
}

$dom      = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8"?>' . $markup);
libxml_clear_errors();
libxml_use_internal_errors($previous);

$xpath = new DOMXPath($dom);

// Ensure the container exists and carries the expected AMP-safe attributes.
$containerNodes = $xpath->query('//div[contains(@class, "wwt-toc-container")]');
if (!$containerNodes instanceof DOMNodeList || $containerNodes->length === 0) {
    fwrite(STDERR, "AMP markup is missing the TOC container element.\n");
    exit(1);
}

$container = $containerNodes->item(0);

if ($container->hasAttribute('style')) {
    fwrite(STDERR, "AMP markup must not include inline style attributes on the container.\n");
    exit(1);
}

if ($container->getAttribute('data-render-mode') !== 'static') {
    fwrite(STDERR, "AMP markup must use the static render mode on the container.\n");
    exit(1);
}

if (!preg_match('/\bwwt-color-scheme-/', $container->getAttribute('class'))) {
    fwrite(STDERR, "AMP markup should expose a deterministic color scheme class.\n");
    exit(1);
}

// Ensure no disallowed elements or attributes appear in the markup.
$disallowedTags = $xpath->query('//script | //style | //button');
if ($disallowedTags instanceof DOMNodeList && $disallowedTags->length > 0) {
    fwrite(STDERR, "AMP markup contains disallowed tags (script/style/button).\n");
    exit(1);
}

foreach ($xpath->query('//@*') as $attribute) {
    if (strpos($attribute->nodeName, 'on') === 0) {
        fwrite(STDERR, "AMP markup contains a disallowed on* attribute: {$attribute->nodeName}.\n");
        exit(1);
    }
}

$detailsNodes = $xpath->query('//details[@class="wwt-toc-accordion"]');
if (!$detailsNodes instanceof DOMNodeList || $detailsNodes->length === 0) {
    fwrite(STDERR, "AMP markup must wrap links inside a <details> accordion.");
    exit(1);
}

$navNodes = $xpath->query('//nav[@role="doc-toc"]');
if (!$navNodes instanceof DOMNodeList || $navNodes->length === 0) {
    fwrite(STDERR, "AMP markup is missing the doc-toc navigation landmark.\n");
    exit(1);
}

$anchorsWithJavascript = $xpath->query('//a[starts-with(@href, "javascript:")]');
if ($anchorsWithJavascript instanceof DOMNodeList && $anchorsWithJavascript->length > 0) {
    fwrite(STDERR, "AMP markup contains anchors with javascript: URLs.\n");
    exit(1);
}

$summaryNodes = $xpath->query('//summary[@class="wwt-toc-summary"]');
if (!$summaryNodes instanceof DOMNodeList || $summaryNodes->length === 0) {
    fwrite(STDERR, "AMP markup is missing the summary element.\n");
    exit(1);
}

// Ensure CSS variables are exposed through data attributes instead of inline styles.
$dataAttributes = ['data-bg', 'data-text', 'data-link', 'data-title-bg', 'data-title-color'];
foreach ($dataAttributes as $dataAttr) {
    if (!$container->hasAttribute($dataAttr)) {
        fwrite(STDERR, "AMP container is missing required {$dataAttr} attribute for color propagation.\n");
        exit(1);
    }
}

// Ensure anchor targets use fragment identifiers derived from headings.
foreach ($xpath->query('//ol[contains(@class, "wwt-toc-list")]/li/a') as $link) {
    /** @var DOMElement $link */
    $href = $link->getAttribute('href');
    if ($href === '' || $href[0] !== '#') {
        fwrite(STDERR, "AMP TOC links must use in-page fragment identifiers.\n");
        exit(1);
    }
}

echo "AMP markup and CSS checks passed.\n";
