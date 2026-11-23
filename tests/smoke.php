<?php
/**
 * Lightweight tests using a minimal WordPress shim.
 * Run with `php tests/smoke.php`; no external dependencies required.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['test_hooks'] = [];
$GLOBALS['test_meta'] = [];
$GLOBALS['test_user_id'] = 0;

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['test_hooks'][] = ['action', $hook];
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['test_hooks'][] = ['filter', $hook];
}
function add_shortcode($tag, $callback) {
    $GLOBALS['test_hooks'][] = ['shortcode', $tag];
}
function absint($value) {
    return abs((int) $value);
}
function get_current_user_id() {
    return $GLOBALS['test_user_id'];
}
function get_user_meta($user_id, $meta_key, $single = true) {
    return $GLOBALS['test_meta'][$user_id][$meta_key] ?? '';
}

// Minimal WP context for testing public content rendering paths.
class WP_Post {
    public $ID;
    public $post_content;
    public function __construct($id, $content) {
        $this->ID = $id;
        $this->post_content = $content;
    }
}
$GLOBALS['test_feed'] = false;
$GLOBALS['test_singular'] = true;
$GLOBALS['test_admin'] = false;
$GLOBALS['test_queried_id'] = 42;
$GLOBALS['test_loop_id'] = 42;
function is_feed() { return $GLOBALS['test_feed']; }
function is_singular() { return $GLOBALS['test_singular']; }
function is_admin() { return $GLOBALS['test_admin']; }
function get_queried_object_id() { return $GLOBALS['test_queried_id']; }
function get_the_ID() { return $GLOBALS['test_loop_id']; }
function is_user_logged_in() { return false; }
function get_post($post) { return $post instanceof WP_Post ? $post : null; }
function strip_shortcodes($text) { return preg_replace('/\\[[^\\]]*\\]/', '', $text); }
function wp_strip_all_tags($text, $remove_breaks = false) { return strip_tags($text); }
function wp_trim_words($text, $num_words = 55, $more = '…') {
    $words = preg_split('/\\s+/', trim($text));
    return count($words) > $num_words
        ? implode(' ', array_slice($words, 0, $num_words)) . $more
        : trim($text);
}

require dirname(__DIR__) . '/goatafight-premium.php';

$passed = 0;
function check($condition, $message) {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$message}\n";
}

check(class_exists('GoataFight_Premium'), 'plugin class loads');
check(in_array(['filter', 'the_content'], $GLOBALS['test_hooks'], true), 'content filter registered');
check(in_array(['filter', 'rank_math/json_ld'], $GLOBALS['test_hooks'], true), 'structured-data filter registered');
check(in_array(['action', 'wp_ajax_goatafight_premium_content'], $GLOBALS['test_hooks'], true), 'premium AJAX action registered');
check(in_array(['shortcode', 'goata_paywall'], $GLOBALS['test_hooks'], true), 'paywall shortcode registered');

$GLOBALS['test_meta'][42]['_goatafight_premium_gift_until'] = 'lifetime';
check(GoataFight_Premium::user_has_gift_access(42), 'unlimited complimentary access');
$GLOBALS['test_meta'][42]['_goatafight_premium_gift_until'] = (string) (time() + 3600);
check(GoataFight_Premium::user_has_gift_access(42), 'unexpired complimentary access');
$GLOBALS['test_meta'][42]['_goatafight_premium_gift_until'] = (string) (time() - 3600);
check(!GoataFight_Premium::user_has_gift_access(42), 'expired complimentary access');
$GLOBALS['test_meta'][42]['_goatafight_premium_gift_until'] = 'invalid';
check(!GoataFight_Premium::user_has_gift_access(42), 'invalid expiry is rejected');
check(!GoataFight_Premium::user_has_gift_access(0), 'anonymous users have no complimentary access');

$split = new ReflectionMethod('GoataFight_Premium', 'split_raw_content');
$split->setAccessible(true);
$result = $split->invoke(null, 'Introduction [goata_paywall] Subscriber analysis');
check(is_array($result) && trim($result['free']) === 'Introduction', 'public article extraction');
check(is_array($result) && trim($result['premium']) === 'Subscriber analysis', 'premium article extraction');
check($split->invoke(null, 'Article without marker') === false, 'unmarked article is unchanged');
$blocks = '<!-- wp:shortcode -->[goata_paywall]<!-- /wp:shortcode -->';
$result = $split->invoke(null, "Intro\n{$blocks}\nSubscriber section");
check(is_array($result) && trim($result['premium']) === 'Subscriber section', 'Gutenberg shortcode block separation');


$private = 'SECRET_SUBSCRIBER_CONTENT_928';
$sample = "Public introduction [goata_paywall] {$private}";
$post = new WP_Post(42, $sample);
check(in_array(['filter', 'get_the_excerpt'], $GLOBALS['test_hooks'], true), 'public excerpt filter registered');

$GLOBALS['test_feed'] = true;
$rendered = GoataFight_Premium::filter_paywall_content($sample);
check(strpos($rendered, 'Public introduction') !== false && strpos($rendered, $private) === false, 'RSS feed hides premium content');
$GLOBALS['test_feed'] = false;

$GLOBALS['test_singular'] = false;
$rendered = GoataFight_Premium::filter_paywall_content($sample);
check(strpos($rendered, $private) === false, 'non-singular REST/archive context hides premium content');
$GLOBALS['test_singular'] = true;

$GLOBALS['test_admin'] = true;
$rendered = GoataFight_Premium::filter_paywall_content($sample);
check(strpos($rendered, $private) === false, 'administrative content filter hides premium section by default');
$GLOBALS['test_admin'] = false;

$GLOBALS['test_queried_id'] = 0;
$GLOBALS['test_loop_id'] = 0;
$rendered = GoataFight_Premium::filter_paywall_content($sample);
check(strpos($rendered, $private) === false, 'missing post context does not expose protected content');
$GLOBALS['test_queried_id'] = 42;
$GLOBALS['test_loop_id'] = 55;
$rendered = GoataFight_Premium::filter_paywall_content($sample);
check(strpos($rendered, $private) === false, 'secondary loop does not expose protected content');
$GLOBALS['test_loop_id'] = 42;

$excerpt = GoataFight_Premium::filter_paywall_excerpt($private, $post);
check(strpos($excerpt, 'Public introduction') !== false && strpos($excerpt, $private) === false, 'explicit excerpt is replaced with public-only content');
check(GoataFight_Premium::filter_paywall_excerpt('Normal excerpt', new WP_Post(15, 'No marker here')) === 'Normal excerpt', 'unprotected excerpts remain unchanged');

print "\n{$passed} checks passed.\n";
