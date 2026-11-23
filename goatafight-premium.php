<?php
/**
 * Plugin Name: GoataFight Premium
 * Description: Intègre GoataFight Premium à Google Reader Revenue Manager : suppression des publicités pour les abonnés, paywall partiel via [goata_paywall], CTA d'abonnement, chargement différé du contenu Premium et balisage Rank Math pour contenu payant.
 * Version: 2.1.0
 * Author: GoataFight
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GoataFight_Premium {
    const VERSION       = '2.1.0';
    const COOKIE_NAME   = 'goatafight_premium';
    const COOKIE_TTL    = 21600; // 6 h : cookie confort / no-ads, revérifié par RRM.
    const ACCESS_COOKIE = 'goatafight_premium_access';
    const ACCESS_TTL    = 900;   // 15 min : session serveur courte pour charger le contenu Premium.
    const MARKER        = '[goata_paywall]';
    const GIFT_META     = '_goatafight_premium_gift_until'; // Timestamp UNIX ou 'lifetime'.

    private static $paywall_rendered = false;
    private static $paywall_post_id  = 0;

    /**
     * Product identifier injected by the site configuration.
     * Portfolio builds intentionally do not embed production account IDs.
     */
    private static function product_id() {
        return defined( 'GOATAFIGHT_RRM_PRODUCT_ID' )
            ? trim( (string) GOATAFIGHT_RRM_PRODUCT_ID )
            : '';
    }

    public static function init() {
        // Abonnements Premium offerts, indépendants de Google RRM.
        add_action( 'admin_menu', array( __CLASS__, 'register_gifts_admin_page' ) );
        add_action( 'admin_post_goatafight_premium_gift_grant', array( __CLASS__, 'admin_grant_gift' ) );
        add_action( 'admin_post_goatafight_premium_gift_revoke', array( __CLASS__, 'admin_revoke_gift' ) );

        // Compatibilité avec shortcode_unautop() de WordPress.
        add_shortcode( 'goata_paywall', '__return_empty_string' );

        // No Ads : conserve la logique du plugin v1.
        add_filter( 'option_googlesitekit_adsense_settings', array( __CLASS__, 'filter_sitekit_adsense_settings' ), 99 );
        add_action( 'template_redirect', array( __CLASS__, 'disable_cache_for_premium' ), 0 );
        add_action( 'wp_head', array( __CLASS__, 'print_entitlement_bridge' ), 0 );
        add_action( 'wp_head', array( __CLASS__, 'print_no_ads_frontend_code' ), 1 );
        add_action( 'wp_head', array( __CLASS__, 'print_premium_promo_visibility_code' ), 2 );

        // Paywall partiel. Priorité 8 : avant le rendu des blocs et avant do_shortcode.
        // On intercepte ainsi le marqueur brut [goata_paywall], y compris avec Gutenberg/Elementor.
        add_filter( 'the_content', array( __CLASS__, 'filter_paywall_content' ), 8 );
        // Les extraits automatiques/REST ne doivent jamais lire au-delà du marqueur Premium.
        add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_paywall_excerpt' ), 9, 2 );
        add_action( 'wp_footer', array( __CLASS__, 'print_paywall_frontend_code' ), 99 );

        // AJAX : session courte + récupération du contenu Premium.
        add_action( 'wp_ajax_goatafight_premium_session', array( __CLASS__, 'ajax_create_premium_session' ) );
        add_action( 'wp_ajax_nopriv_goatafight_premium_session', array( __CLASS__, 'ajax_create_premium_session' ) );
        add_action( 'wp_ajax_goatafight_premium_content', array( __CLASS__, 'ajax_get_premium_content' ) );
        add_action( 'wp_ajax_nopriv_goatafight_premium_content', array( __CLASS__, 'ajax_get_premium_content' ) );
        add_action( 'wp_ajax_goatafight_premium_logout', array( __CLASS__, 'ajax_clear_premium_session' ) );
        add_action( 'wp_ajax_nopriv_goatafight_premium_logout', array( __CLASS__, 'ajax_clear_premium_session' ) );

        // SEO Rank Math : indique la section payante aux moteurs.
        add_filter( 'rank_math/json_ld', array( __CLASS__, 'filter_rank_math_json_ld' ), 99, 2 );
    }

    /* -------------------------------------------------------------------------
     * Premium offert : comptes WordPress + expiration contrôlée côté serveur
     * ---------------------------------------------------------------------- */

    /** Un accès offert est attaché à un compte WordPress, pas au compte Google. */
    public static function user_has_gift_access( $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        $until = get_user_meta( $user_id, self::GIFT_META, true );
        if ( 'lifetime' === $until ) {
            return true;
        }

        return is_scalar( $until ) && ctype_digit( (string) $until ) && (int) $until > time();
    }

    /** À réutiliser si un autre plugin GoataFight contrôle lui aussi des pages Premium. */
    public static function current_user_has_server_access() {
        return self::user_has_gift_access() || self::has_valid_access_cookie();
    }

    public static function register_gifts_admin_page() {
        add_menu_page(
            'GoataFight - Premium offerts',
            'Premium offerts',
            'manage_options',
            'goatafight-premium-gifts',
            array( __CLASS__, 'render_gifts_admin_page' ),
            'dashicons-awards',
            58
        );
    }

    private static function gifts_admin_url( $args = array() ) {
        return add_query_arg( $args, admin_url( 'admin.php?page=goatafight-premium-gifts' ) );
    }

    private static function gift_admin_redirect( $notice ) {
        wp_safe_redirect( self::gifts_admin_url( array( 'goata_notice' => $notice ) ) );
        exit;
    }

    /** Accepte un identifiant WP existant ou crée un abonné à partir d'un e-mail. */
    private static function resolve_gift_user( $account, &$created ) {
        $created = false;
        $account = trim( (string) $account );
        if ( '' === $account ) {
            return new WP_Error( 'missing_account', 'Saisis un e-mail ou un identifiant WordPress.' );
        }

        if ( is_email( $account ) ) {
            $user = get_user_by( 'email', $account );
            if ( $user ) {
                return $user;
            }

            $parts = explode( '@', $account, 2 );
            $base  = sanitize_user( $parts[0], true );
            $base  = substr( $base ? $base : 'invite-premium', 0, 45 );
            $login = $base;
            $suffix = 1;
            while ( username_exists( $login ) ) {
                $login = $base . '-' . $suffix;
                $suffix++;
                if ( $suffix > 10000 ) {
                    return new WP_Error( 'no_username', 'Impossible de créer un identifiant unique.' );
                }
            }

            $user_id = wp_insert_user( array(
                'user_login' => $login,
                'user_email' => $account,
                'user_pass'  => wp_generate_password( 32, true, true ),
                'role'       => 'subscriber',
            ) );
            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }

            $created = true;
            return get_user_by( 'id', $user_id );
        }

        $user = get_user_by( 'login', $account );
        if ( ! $user ) {
            return new WP_Error( 'unknown_login', 'Identifiant introuvable. Utilise son e-mail pour créer un compte.' );
        }
        return $user;
    }

    private static function send_gift_notice( $user, $expiry ) {
        $date = 'lifetime' === $expiry ? 'sans date de fin' : 'jusqu’au ' . wp_date( 'd/m/Y à H:i', (int) $expiry );
        $subject = 'Ton accès GoataFight Premium est offert';
        $body = "Bonjour,\n\nUn accès GoataFight Premium t'a été offert " . $date . ".\n";
        $body .= "Connecte-toi avec ton compte WordPress pour en profiter : " . wp_login_url( home_url( '/' ) ) . "\n\nÀ bientôt sur GoataFight !";
        wp_mail( $user->user_email, $subject, $body );
    }

    public static function admin_grant_gift() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }
        check_admin_referer( 'goatafight_premium_gift_grant' );

        $account = isset( $_POST['gift_account'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_account'] ) ) : '';
        $duration = isset( $_POST['gift_duration'] ) ? sanitize_key( wp_unslash( $_POST['gift_duration'] ) ) : '';
        $allowed = array( '7' => 7, '30' => 30, '90' => 90, '365' => 365, 'lifetime' => 0 );
        if ( ! array_key_exists( $duration, $allowed ) ) {
            self::gift_admin_redirect( 'invalid_duration' );
        }

        $created = false;
        $user = self::resolve_gift_user( $account, $created );
        if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
            self::gift_admin_redirect( 'invalid_user' );
        }

        // Renouveler repart de maintenant, sans toucher à un éventuel abonnement Google.
        $expiry = 'lifetime' === $duration ? 'lifetime' : (string) ( time() + $allowed[ $duration ] * DAY_IN_SECONDS );
        update_user_meta( $user->ID, self::GIFT_META, $expiry );
        update_user_meta( $user->ID, '_goatafight_premium_gift_granted_by', get_current_user_id() );
        update_user_meta( $user->ID, '_goatafight_premium_gift_granted_at', time() );

        if ( $created ) {
            // Invitation native WordPress contenant un lien sécurisé pour définir son mot de passe.
            if ( function_exists( 'wp_new_user_notification' ) ) {
                wp_new_user_notification( $user->ID, null, 'user' );
            }
            if ( ! empty( $_POST['gift_notify'] ) ) {
                self::send_gift_notice( $user, $expiry );
            }
            self::gift_admin_redirect( 'created' );
        }

        // Pour les comptes déjà existants, prévenir uniquement si demandé.
        if ( ! empty( $_POST['gift_notify'] ) ) {
            self::send_gift_notice( $user, $expiry );
        }
        self::gift_admin_redirect( 'granted' );
    }

    public static function admin_revoke_gift() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }
        $user_id = isset( $_POST['gift_user_id'] ) ? absint( $_POST['gift_user_id'] ) : 0;
        check_admin_referer( 'goatafight_premium_gift_revoke_' . $user_id );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            self::gift_admin_redirect( 'invalid_user' );
        }

        delete_user_meta( $user_id, self::GIFT_META );
        self::gift_admin_redirect( 'revoked' );
    }

    public static function render_gifts_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }

        $notices = array(
            'created'          => 'Accès offert activé. Nouveau compte WordPress créé : l’invitation de connexion a été demandée par e-mail.',
            'granted'          => 'Accès Premium offert activé ou renouvelé.',
            'revoked'          => 'Accès Premium offert révoqué.',
            'invalid_duration' => 'Durée non valide.',
            'invalid_user'     => 'Utilisateur introuvable ou e-mail non valide. Vérifie les informations saisies.',
        );
        $notice = isset( $_GET['goata_notice'] ) ? sanitize_key( wp_unslash( $_GET['goata_notice'] ) ) : '';
        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $query = new WP_User_Query( array(
            'meta_key'    => self::GIFT_META,
            'number'      => 25,
            'paged'       => $page,
            'orderby'     => 'registered',
            'order'       => 'DESC',
            'count_total' => true,
        ) );
        ?>
        <div class="wrap">
            <h1>GoataFight Premium - Accès offerts</h1>
            <p>Offre le Premium sans passer par un paiement Google. L’accès est lié au <strong>compte WordPress</strong> du bénéficiaire, et non à son compte Google RRM.</p>
            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice <?php echo in_array( $notice, array( 'invalid_duration', 'invalid_user' ), true ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
            <?php endif; ?>

            <div class="postbox" style="max-width:860px;padding:20px 24px;margin-top:20px;">
                <h2 style="margin-top:0">Offrir ou renouveler un accès</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="goatafight_premium_gift_grant">
                    <?php wp_nonce_field( 'goatafight_premium_gift_grant' ); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th scope="row"><label for="goatafight-gift-account">Bénéficiaire</label></th><td>
                            <input type="text" id="goatafight-gift-account" name="gift_account" class="regular-text" required placeholder="email@exemple.fr ou identifiant WP">
                            <p class="description">E-mail déjà connu : accès sur le compte existant. Nouvel e-mail : création automatique d’un compte WordPress « Abonné » et invitation pour choisir un mot de passe.</p>
                        </td></tr>
                        <tr><th scope="row"><label for="goatafight-gift-duration">Durée</label></th><td>
                            <select name="gift_duration" id="goatafight-gift-duration">
                                <option value="7">7 jours</option>
                                <option value="30" selected>30 jours</option>
                                <option value="90">90 jours</option>
                                <option value="365">1 an (365 jours)</option>
                                <option value="lifetime">Illimité</option>
                            </select>
                            <p class="description">Une nouvelle attribution remplace la date d’expiration précédente. L’accès s’arrête automatiquement à l’échéance.</p>
                        </td></tr>
                        <tr><th scope="row">Notification</th><td>
                            <label><input type="checkbox" name="gift_notify" value="1" checked> Envoyer un e-mail annonçant le Premium offert</label>
                            <p class="description">Pour un nouveau compte, WordPress envoie aussi un lien pour choisir son mot de passe. Vérifie la délivrabilité des e-mails du site.</p>
                        </td></tr>
                    </tbody></table>
                    <?php submit_button( 'Offrir le Premium' ); ?>
                </form>
            </div>

            <h2>Accès attribués</h2>
            <p>Les comptes doivent être connectés sur GoataFight avec leur identifiant WordPress : <a href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>" target="_blank" rel="noopener noreferrer">lien de connexion</a>. Les abonnements Google RRM ne sont pas modifiés.</p>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>Compte</th><th>E-mail</th><th>Statut</th><th>Échéance</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ( $query->get_results() as $user ) :
                    $until = get_user_meta( $user->ID, self::GIFT_META, true );
                    $active = self::user_has_gift_access( $user->ID );
                    $date_label = 'lifetime' === $until ? 'Illimité' : ( ctype_digit( (string) $until ) ? wp_date( 'd/m/Y H:i', (int) $until ) : 'Non défini' );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $user->user_login ); ?></strong></td>
                        <td><?php echo esc_html( $user->user_email ); ?></td>
                        <td><?php echo $active ? '<strong style="color:#12652a">Actif</strong>' : '<span style="color:#a00">Expiré</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <td><?php echo esc_html( $date_label ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Révoquer cet accès Premium offert ?');">
                                <input type="hidden" name="action" value="goatafight_premium_gift_revoke">
                                <input type="hidden" name="gift_user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                <?php wp_nonce_field( 'goatafight_premium_gift_revoke_' . $user->ID ); ?>
                                <button type="submit" class="button">Révoquer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( ! $query->get_results() ) : ?>
                    <tr><td colspan="5">Aucun accès offert pour le moment.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php
            $total_pages = (int) ceil( (int) $query->get_total() / 25 );
            if ( $total_pages > 1 ) {
                echo wp_kses_post( paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%', self::gifts_admin_url() ),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                    'type'    => 'plain',
                ) ) );
            }
            ?>
        </div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    private static function has_premium_cookie() {
        return isset( $_COOKIE[ self::COOKIE_NAME ] ) && '1' === (string) $_COOKIE[ self::COOKIE_NAME ];
    }

    private static function current_user_agent_hash() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
        return hash( 'sha256', $ua );
    }

    private static function build_access_cookie_value( $expires ) {
        $expires = (int) $expires;
        $payload = $expires . '|' . self::current_user_agent_hash();
        $sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        return $expires . '.' . $sig;
    }

    private static function has_valid_access_cookie() {
        if ( empty( $_COOKIE[ self::ACCESS_COOKIE ] ) ) {
            return false;
        }

        $raw = (string) wp_unslash( $_COOKIE[ self::ACCESS_COOKIE ] );
        if ( false === strpos( $raw, '.' ) ) {
            return false;
        }

        list( $expires, $sig ) = explode( '.', $raw, 2 );
        $expires = (int) $expires;

        if ( $expires < time() || empty( $sig ) ) {
            return false;
        }

        $expected = self::build_access_cookie_value( $expires );
        return hash_equals( $expected, $raw );
    }

    private static function set_access_cookie() {
        $expires = time() + self::ACCESS_TTL;
        $value   = self::build_access_cookie_value( $expires );

        setcookie(
            self::ACCESS_COOKIE,
            $value,
            array(
                'expires'  => $expires,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );

        $_COOKIE[ self::ACCESS_COOKIE ] = $value;
    }

    private static function clear_access_cookie() {
        setcookie(
            self::ACCESS_COOKIE,
            '',
            array(
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );

        unset( $_COOKIE[ self::ACCESS_COOKIE ] );
    }

    private static function post_has_paywall( $post_id ) {
        $content = get_post_field( 'post_content', (int) $post_id );
        return is_string( $content ) && false !== stripos( $content, self::MARKER );
    }

    private static function split_raw_content( $content ) {
        $pattern = '/(?:<!--\s*wp:shortcode\s*-->\s*)?\[goata_paywall\](?:\s*<!--\s*\/wp:shortcode\s*-->)?/i';
        $parts   = preg_split( $pattern, (string) $content, 2 );

        if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
            return false;
        }

        return array(
            'free'    => $parts[0],
            'premium' => $parts[1],
        );
    }

    private static function is_admin_test_mode( $mode ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        if ( ! isset( $_GET['goata_premium_test'] ) ) {
            return false;
        }

        return sanitize_key( wp_unslash( $_GET['goata_premium_test'] ) ) === $mode;
    }


    /**
     * Reader Revenue Manager Standard uses SWG_BASIC, not SWG. Register this
     * listener before Site Kit initializes its RRM client in wp_head.
     * SwG (Enterprise) is kept as a fallback for compatible installations.
     */
    public static function print_entitlement_bridge() {
        if ( is_admin() ) {
            return;
        }
        $product_id = wp_json_encode( self::product_id() );
        ?>
        <script id="goatafight-premium-rrm-bridge">
        (function () {
            'use strict';
            var PRODUCT_ID = <?php echo $product_id; ?>;
            var hasPositive = false;
            var lastApi = null;

            function getJwt(entitlements) {
                if (!entitlements || typeof entitlements !== 'object') return '';
                var raw = entitlements.raw || entitlements.signedEntitlements || '';
                if (raw && typeof raw === 'object') raw = raw.signedEntitlements || raw.raw || '';
                if (!raw && typeof entitlements.getRaw === 'function') {
                    try { raw = entitlements.getRaw(); } catch (err) {}
                }
                if (!raw && typeof entitlements.getJwt === 'function') {
                    try { raw = entitlements.getJwt(); } catch (err) {}
                }
                if (typeof raw === 'string' && raw.charAt(0) === '{') {
                    try { raw = JSON.parse(raw).signedEntitlements || ''; } catch (err) { raw = ''; }
                }
                return typeof raw === 'string' && /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(raw) ? raw : '';
            }

            function interpret(entitlements, source) {
                var premium = false;
                try {
                    if (entitlements && typeof entitlements.enables === 'function') {
                        premium = !!entitlements.enables(PRODUCT_ID);
                    }
                    // Official RRM Standard check (swg-basic.js).
                    if (!premium && source === 'SWG_BASIC' && entitlements && typeof entitlements.enablesThisWithCacheableEntitlements === 'function') {
                        premium = !!entitlements.enablesThisWithCacheableEntitlements();
                    }
                } catch (err) { premium = false; }
                if (premium) {
                    hasPositive = true;
                    if (entitlements && typeof entitlements.ack === 'function') {
                        try { entitlements.ack(); } catch (err) {}
                    }
                }
                // Do not let an unrelated client reporting no access override a valid purchase.
                if (!premium && hasPositive) return;
                var detail = { checked: true, premium: premium, source: source, signedEntitlements: premium ? getJwt(entitlements) : '' };
                window.GoataFightPremiumResult = detail;
                window.dispatchEvent(new CustomEvent('goatafight-premium-entitlement', { detail: detail }));
            }

            function onError(error, source) {
                if (hasPositive) return;
                var detail = { checked: false, premium: false, source: source, signedEntitlements: '', error: 'Vérification Google indisponible.' };
                window.GoataFightPremiumResult = detail;
                window.dispatchEvent(new CustomEvent('goatafight-premium-entitlement', { detail: detail }));
            }

            function connect(queueName) {
                (self[queueName] = self[queueName] || []).push(function (client) {
                    lastApi = client;
                    try {
                        if (client && typeof client.setOnEntitlementsResponse === 'function') {
                            client.setOnEntitlementsResponse(function (promise) {
                                Promise.resolve(promise).then(function (e) {
                                    interpret(e, queueName);
                                }).catch(function (err) { onError(err, queueName); });
                            });
                        }
                        if (client && typeof client.getEntitlements === 'function') {
                            Promise.resolve(client.getEntitlements()).then(function (e) {
                                interpret(e, queueName);
                            }).catch(function (err) { onError(err, queueName); });
                        }
                    } catch (err) { onError(err, queueName); }
                });
            }
            connect('SWG_BASIC');
            connect('SWG');
            window.GoataFightPremiumRecheck = function () {
                if (lastApi && typeof lastApi.getEntitlements === 'function') {
                    return Promise.resolve(lastApi.getEntitlements()).then(function (e) {
                        interpret(e, 'SWG_BASIC');
                    });
                }
                // RRM Basic callback fires when Google sends its next entitlement response.
                window.dispatchEvent(new CustomEvent('goatafight-premium-recheck-unavailable'));
                return Promise.resolve();
            };
        })();
        </script>
        <?php
    }

    /**
     * Masque uniquement les encarts promotionnels .gfpb pour les abonnes.
     * L'etat d'abonnement est recu du pont RRM/SWG_BASIC existant.
     * Ne jamais utiliser le cookie de confort comme preuve d'acces au contenu payant.
     * Le masquage initial evite un flash de la banniere lors du chargement.
     */
    public static function print_premium_promo_visibility_code() {
        if ( is_admin() ) {
            return;
        }
        $gift_access = wp_json_encode( self::user_has_gift_access() );
        ?>
        <style id="goatafight-premium-promo-visibility-css">
            html.goatafight-premium-promo-pending .gfpb,
            html.goatafight-premium-promo-hidden .gfpb {
                display: none !important;
            }
        </style>
        <script id="goatafight-premium-promo-visibility-js">
        (function () {
            'use strict';
            var root = document.documentElement;
            if (!root) return;
            var pending = 'goatafight-premium-promo-pending';
            var hidden  = 'goatafight-premium-promo-hidden';
            var showTimer = null;
            var premiumConfirmed = false;
            var GIFT_ACCESS = <?php echo $gift_access; ?>;

            // Execution dans <head> : la section ne clignote pas avant la verification RRM.
            root.classList.add(pending);

            function hidePromo() {
                premiumConfirmed = true;
                if (showTimer !== null) clearTimeout(showTimer);
                showTimer = null;
                root.classList.add(hidden);
                root.classList.remove(pending);
            }

            function showPromo() {
                if (premiumConfirmed) return;
                if (showTimer !== null) clearTimeout(showTimer);
                showTimer = null;
                root.classList.remove(hidden);
                root.classList.remove(pending);
            }

            function onEntitlement(result) {
                if (!result) return;
                if (result.checked && result.premium) {
                    hidePromo();
                    return;
                }
                if (result.checked && !result.premium) {
                    // Les deux clients RRM peuvent repondre dans un ordre different.
                    // Laisser une courte marge avant d'afficher aux non-abonnes.
                    if (showTimer !== null) clearTimeout(showTimer);
                    showTimer = setTimeout(showPromo, 1100);
                } else if (result.checked === false) {
                    // Indisponibilite RRM : ne pas cacher indefiniment la promotion.
                    showPromo();
                }
            }

            window.addEventListener('goatafight-premium-entitlement', function (event) {
                onEntitlement(event.detail);
            });
            if (GIFT_ACCESS) hidePromo();
            onEntitlement(window.GoataFightPremiumResult);

            // Securite : ne jamais masquer la promotion indefiniment quand Google ne repond pas.
            setTimeout(showPromo, 6000);
        })();
        </script>
        <?php
    }

    /* -------------------------------------------------------------------------
     * No Ads
     * ---------------------------------------------------------------------- */

    public static function filter_sitekit_adsense_settings( $settings ) {
        if ( is_admin() || ! is_array( $settings ) || ( ! self::has_premium_cookie() && ! self::user_has_gift_access() ) ) {
            return $settings;
        }

        $settings['useSnippet'] = false;
        return $settings;
    }

    public static function disable_cache_for_premium() {
        if ( is_admin() || ( ! self::has_premium_cookie() && ! self::current_user_has_server_access() ) ) {
            return;
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        do_action( 'litespeed_control_set_nocache', 'GoataFight Premium' );
    }

    public static function print_no_ads_frontend_code() {
        if ( is_admin() ) {
            return;
        }

        $product_id  = wp_json_encode( self::product_id() );
        $cookie_name = wp_json_encode( self::COOKIE_NAME );
        $cookie_ttl  = (int) self::COOKIE_TTL;
        $gift_access = wp_json_encode( self::user_has_gift_access() );
        ?>
        <style id="goatafight-premium-no-ads-css">
            html.goatafight-premium-no-ads ins.adsbygoogle,
            html.goatafight-premium-no-ads .adsbygoogle,
            html.goatafight-premium-no-ads .google-auto-placed,
            html.goatafight-premium-no-ads [id^="google_ads_iframe_"],
            html.goatafight-premium-no-ads [id^="aswift_"],
            html.goatafight-premium-no-ads iframe[src*="googlesyndication.com"],
            html.goatafight-premium-no-ads iframe[src*="doubleclick.net"] {
                display: none !important;
                visibility: hidden !important;
                width: 0 !important;
                height: 0 !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
            }
        </style>
        <script id="goatafight-premium-no-ads-js">
        (function () {
            'use strict';

            var PRODUCT_ID = <?php echo $product_id; ?>;
            var COOKIE_NAME = <?php echo $cookie_name; ?>;
            var COOKIE_TTL = <?php echo $cookie_ttl; ?>;
            var GIFT_ACCESS = <?php echo $gift_access; ?>;
            var observer = null;

            function hasCookie() {
                return document.cookie.split(';').some(function (item) {
                    return item.trim().indexOf(COOKIE_NAME + '=1') === 0;
                });
            }

            function setPremiumCookie() {
                var secure = location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = COOKIE_NAME + '=1; Path=/; Max-Age=' + COOKIE_TTL + '; SameSite=Lax' + secure;
            }

            function clearPremiumCookie() {
                var secure = location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = COOKIE_NAME + '=; Path=/; Max-Age=0; SameSite=Lax' + secure;
            }

            function removeAds() {
                document.documentElement.classList.add('goatafight-premium-no-ads');

                var selectors = [
                    'ins.adsbygoogle',
                    '.google-auto-placed',
                    '[id^="google_ads_iframe_"]',
                    '[id^="aswift_"]',
                    'iframe[src*="googlesyndication.com"]',
                    'iframe[src*="doubleclick.net"]'
                ];

                try {
                    document.querySelectorAll(selectors.join(',')).forEach(function (el) {
                        var parent = el.closest ? el.closest('.google-auto-placed') : null;
                        (parent || el).remove();
                    });
                } catch (e) {}
            }

            function enablePremiumMode() {
                if (document.body) document.body.classList.add('google-anno-skip');
                setPremiumCookie();
                removeAds();

                if (!observer && document.documentElement) {
                    observer = new MutationObserver(function () {
                        removeAds();
                    });
                    observer.observe(document.documentElement, { childList: true, subtree: true });
                }
            }

            function disablePremiumMode() {
                if (document.body) document.body.classList.remove('google-anno-skip');
                clearPremiumCookie();
                document.documentElement.classList.remove('goatafight-premium-no-ads');
                if (observer) {
                    observer.disconnect();
                    observer = null;
                }
            }

            window.GoataFightPremiumAds = {
                enable: enablePremiumMode,
                disable: disablePremiumMode,
                productId: PRODUCT_ID
            };

            if (hasCookie()) {
                removeAds();
                // AdSense "ad intents" are text annotations, not regular ins.adsbygoogle ads.
                // Ask AdSense not to annotate this page as soon as the body exists.
                if (document.body) {
                    document.body.classList.add('google-anno-skip');
                } else {
                    document.addEventListener('DOMContentLoaded', function () {
                        if (hasCookie()) document.body.classList.add('google-anno-skip');
                    }, { once: true });
                }
            }

            function applyGoogleState(detail) {
                // Un retour Google negatif n'annule jamais un Premium offert par WordPress.
                if (GIFT_ACCESS || !detail || !detail.checked) return;
                if (detail.premium) enablePremiumMode();
                else disablePremiumMode();
            }
            window.addEventListener('goatafight-premium-entitlement', function (event) {
                applyGoogleState(event.detail);
            });
            if (GIFT_ACCESS) enablePremiumMode();
            applyGoogleState(window.GoataFightPremiumResult);
        })();
        </script>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Paywall rendering
     * ---------------------------------------------------------------------- */

    /**
     * Public output must never receive the subscriber section.
     * WordPress also renders the_content for feeds, REST and secondary queries;
     * checking only is_singular() would leave those channels unprotected.
     */
    public static function filter_paywall_content( $content ) {
        if ( false === stripos( (string) $content, self::MARKER ) ) {
            return $content;
        }

        $parts = self::split_raw_content( $content );
        if ( ! $parts ) {
            return $content;
        }

        // Ne pas insérer de CTA dynamique dans les flux, réponses REST, archives,
        // requêtes secondaires hors contexte ou écrans d'administration.
        // Dans tous ces cas, renvoyer uniquement la partie publique.
        if ( is_feed() || ! is_singular() || is_admin() ) {
            return $parts['free'];
        }

        $post_id = (int) get_queried_object_id();
        if ( ! $post_id ) {
            $post_id = (int) get_the_ID();
        }
        if ( ! $post_id ) {
            return $parts['free'];
        }

        // Une boucle secondaire ne doit pas renvoyer le texte Premium brut.
        $current_post_id = (int) get_the_ID();
        if ( $current_post_id && $current_post_id !== $post_id ) {
            return $parts['free'];
        }

        // Aperçu explicitement demandé par un administrateur authentifié.
        if ( self::is_admin_test_mode( 'premium' ) ) {
            return $parts['free'] . self::admin_test_badge( 'premium' ) . '<div class="goatafight-premium-content">' . $parts['premium'] . '</div>';
        }

        self::$paywall_rendered = true;
        self::$paywall_post_id  = $post_id;

        return $parts['free'] . self::render_paywall_box( $post_id, $parts['premium'] );
    }

    /**
     * Core uses get_the_excerpt for archive and REST excerpts. A generated
     * excerpt can be built directly from post_content without the_content.
     * Even an explicitly stored excerpt is replaced by a public-only teaser
     * for posts carrying the paywall marker.
     */
    public static function filter_paywall_excerpt( $excerpt, $post = null ) {
        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post );
        }
        if ( ! $post instanceof WP_Post || ! is_string( $post->post_content ) ) {
            return $excerpt;
        }

        $parts = self::split_raw_content( $post->post_content );
        if ( ! $parts ) {
            return $excerpt;
        }

        $text = strip_shortcodes( $parts['free'] );
        $text = wp_strip_all_tags( $text, true );
        $text = preg_replace( '/\\s+/u', ' ', (string) $text );
        return wp_trim_words( trim( (string) $text ), 55, '…' );
    }

    private static function admin_test_badge( $mode ) {
        $label = 'premium' === $mode ? 'TEST ADMIN : CONTENU PREMIUM DÉBLOQUÉ' : 'TEST ADMIN : PAYWALL VERROUILLÉ';
        return '<div style="margin:24px 0;padding:10px 14px;background:#111;color:#FAC51F;font-weight:800;border-left:4px solid #FAC51F;font-size:13px;">' . esc_html( $label ) . '</div>';
    }

    private static function build_teaser_text( $premium_raw ) {
        // N'envoie au navigateur qu'un court extrait texte de la partie Premium.
        // Le contenu complet reste chargé uniquement après validation de l'accès.
        $text = strip_shortcodes( (string) $premium_raw );
        $text = wp_strip_all_tags( $text, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text );
        $text = trim( (string) $text );

        if ( '' === $text ) {
            return 'La suite de cet article contient l’analyse complète, les éléments clés et la conclusion réservés aux abonnés GoataFight Premium.';
        }

        return wp_trim_words( $text, 42, '…' );
    }

    private static function render_paywall_box( $post_id, $premium_raw = '' ) {
        $post_id = (int) $post_id;
        $nonce   = wp_create_nonce( 'goatafight_premium_content_' . $post_id );
        $teaser  = self::build_teaser_text( $premium_raw );

        ob_start();
        ?>
        <section
            class="goatafight-paywall goatafight-premium-content"
            id="goatafight-paywall-<?php echo esc_attr( $post_id ); ?>"
            data-post-id="<?php echo esc_attr( $post_id ); ?>"
            data-content-nonce="<?php echo esc_attr( $nonce ); ?>"
            aria-live="polite"
        >
            <?php if ( self::is_admin_test_mode( 'locked' ) ) : ?>
                <?php echo self::admin_test_badge( 'locked' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>

            <div class="goatafight-paywall__teaser" aria-hidden="true">
                <p><?php echo esc_html( $teaser ); ?></p>
            </div>

            <div class="goatafight-paywall__card">
                <div class="goatafight-paywall__eyebrow">LA SUITE DE L’ARTICLE · GOATAFIGHT PREMIUM</div>
                <h2 class="goatafight-paywall__title">Continue ta lecture</h2>
                <p class="goatafight-paywall__text">
                    Débloque immédiatement la suite de cet article et profite de GoataFight sans publicité.
                </p>

                <div class="goatafight-paywall__benefits" aria-label="Avantages Premium">
                    <span>ARTICLE COMPLET</span>
                    <span>ANALYSES APPROFONDIES</span>
                    <span>PAS DE PUB</span>
                    <span>SOUTIENS UN MÉDIA MMA INDÉPENDANT</span>
                </div>

                <div class="goatafight-paywall__price"><strong>1,20 €</strong><span>/ mois</span></div>

                <div class="goatafight-paywall__button-wrap">
                    <button swg-standard-button="subscription" class="goatafight-paywall__swg-button">DÉBLOQUER ET LIRE LA SUITE</button>
                </div>

                <div class="goatafight-paywall__button-note">Accès Premium à l’article complet</div>

                <?php if ( ! is_user_logged_in() ) : ?>
                    <div class="goatafight-paywall__gift-login">
                        Accès Premium offert ? <a href="<?php echo esc_url( wp_login_url( get_permalink( $post_id ) ) ); ?>">Connecte-toi avec ton compte WordPress</a>.
                    </div>
                <?php endif; ?>

                <button type="button" class="goatafight-paywall__refresh" data-goatafight-refresh>
                    Déjà abonné ? Actualiser mon accès
                </button>

                <div class="goatafight-paywall__status" data-goatafight-status></div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function print_paywall_frontend_code() {
        if ( is_admin() || ! self::$paywall_rendered || ! self::$paywall_post_id ) {
            return;
        }

        $product_id    = wp_json_encode( self::product_id() );
        $ajax_url      = wp_json_encode( admin_url( 'admin-ajax.php' ) );
        $session_nonce = wp_json_encode( wp_create_nonce( 'goatafight_premium_session' ) );
        $logout_nonce  = wp_json_encode( wp_create_nonce( 'goatafight_premium_logout' ) );
        $gift_access   = wp_json_encode( self::user_has_gift_access() );
        ?>
        <style id="goatafight-premium-paywall-css">
            .goatafight-paywall {
                position: relative;
                margin: 10px 0 34px;
                color: inherit;
                text-align: center;
            }
            .goatafight-paywall__teaser {
                position: relative;
                z-index: 1;
                overflow: hidden;
                max-height: 145px;
                margin: 0 10px -31px;
                padding: 2px 2px 60px;
                text-align: left;
                pointer-events: none;
                -webkit-mask-image: linear-gradient(to bottom, #000 0%, #000 34%, rgba(0,0,0,.72) 58%, rgba(0,0,0,.20) 80%, transparent 100%);
                mask-image: linear-gradient(to bottom, #000 0%, #000 34%, rgba(0,0,0,.72) 58%, rgba(0,0,0,.20) 80%, transparent 100%);
            }
            .goatafight-paywall__teaser::after {
                content: "";
                position: absolute;
                inset: auto 0 0;
                height: 76px;
                background: linear-gradient(to bottom, transparent, rgba(255,255,255,.96));
            }
            .goatafight-paywall__teaser p {
                margin: 0 !important;
                color: inherit;
                font-size: inherit;
                line-height: inherit;
            }
            .goatafight-paywall__card {
                position: relative;
                z-index: 2;
                overflow: hidden;
                padding: 34px 30px 28px;
                background: #090909;
                color: #fff;
                border: 1px solid rgba(250,197,31,.38);
                border-radius: 18px;
                box-shadow: 0 18px 50px rgba(0,0,0,.20);
            }
            .goatafight-paywall__card::before {
                content: "";
                position: absolute;
                inset: 0 0 auto 0;
                height: 4px;
                background: #FAC51F;
            }
            .goatafight-paywall__eyebrow {
                display: inline-block;
                margin-bottom: 10px;
                color: #FAC51F;
                font-size: 13px;
                line-height: 1.2;
                font-weight: 900;
                letter-spacing: .08em;
            }
            .goatafight-paywall__title {
                margin: 0 0 10px !important;
                color: #fff !important;
                font-size: clamp(29px, 4vw, 42px) !important;
                line-height: 1.05 !important;
                font-weight: 900 !important;
                text-transform: uppercase;
            }
            .goatafight-paywall__text {
                max-width: 690px;
                margin: 0 auto 22px !important;
                color: #dedede;
                font-size: 17px;
                line-height: 1.55;
            }
            .goatafight-paywall__benefits {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 9px;
                max-width: 820px;
                margin: 0 auto 23px;
            }
            .goatafight-paywall__benefits span {
                padding: 9px 12px;
                color: #fff;
                background: #171717;
                border: 1px solid #292929;
                border-radius: 999px;
                font-size: 12px;
                line-height: 1;
                font-weight: 800;
            }
            .goatafight-paywall__benefits span:nth-child(1),
            .goatafight-paywall__benefits span:nth-child(3) {
                color: #090909;
                background: #FAC51F;
                border-color: #FAC51F;
            }
            .goatafight-paywall__price {
                display: flex;
                align-items: baseline;
                justify-content: center;
                gap: 6px;
                margin-bottom: 17px;
            }
            .goatafight-paywall__price strong {
                color: #FAC51F;
                font-size: 36px;
                line-height: 1;
                font-weight: 900;
            }
            .goatafight-paywall__price span {
                color: #aaa;
                font-size: 15px;
                font-weight: 700;
            }
            .goatafight-paywall__button-wrap {
                display: flex;
                justify-content: center;
                min-height: 52px;
            }
            .goatafight-paywall__swg-button,
            .goatafight-paywall [swg-standard-button="subscription"] {
                min-width: 310px;
                min-height: 52px;
                padding: 0 24px;
                border: 0;
                border-radius: 10px;
                background: #FAC51F;
                color: #090909;
                font-size: 15px;
                line-height: 1.15;
                font-weight: 900;
                cursor: pointer;
                box-shadow: 0 8px 24px rgba(250,197,31,.16);
            }
            .goatafight-paywall__button-note {
                margin-top: 8px;
                color: #8f8f8f;
                font-size: 12px;
                font-weight: 700;
            }
            .goatafight-paywall__gift-login {
                margin: 13px 0 0;
                color: #c5c5c5;
                font-size: 13px;
            }
            .goatafight-paywall__gift-login a { color: #FAC51F; text-decoration: underline; }
            .goatafight-paywall__refresh {
                margin-top: 10px;
                padding: 5px 8px;
                border: 0;
                background: transparent;
                color: #bdbdbd;
                text-decoration: underline;
                text-underline-offset: 3px;
                cursor: pointer;
                font-size: 13px;
            }
            .goatafight-paywall__refresh:hover { color: #fff; }
            .goatafight-paywall__status {
                min-height: 20px;
                margin-top: 10px;
                color: #bbb;
                font-size: 13px;
            }
            .goatafight-paywall__status.is-error { color: #ff9b9b; }
            .goatafight-paywall__status.is-ok { color: #FAC51F; font-weight: 800; }
            .goatafight-premium-loading {
                margin: 30px 0;
                padding: 22px;
                border-left: 4px solid #FAC51F;
                background: #111;
                color: #fff;
                font-weight: 800;
            }
            @media (max-width: 680px) {
                .goatafight-paywall {
                    margin: 6px -4px 28px;
                }
                .goatafight-paywall__teaser {
                    max-height: 128px;
                    margin: 0 8px -28px;
                    padding-bottom: 55px;
                }
                .goatafight-paywall__card {
                    padding: 29px 17px 24px;
                    border-radius: 14px;
                }
                .goatafight-paywall__eyebrow { font-size: 11px; }
                .goatafight-paywall__text { font-size: 15px; }
                .goatafight-paywall__benefits { gap: 7px; }
                .goatafight-paywall__benefits span { font-size: 10.5px; padding: 8px 9px; }
                .goatafight-paywall__swg-button,
                .goatafight-paywall [swg-standard-button="subscription"] {
                    width: 100%;
                    min-width: 0;
                    padding: 0 12px;
                    font-size: 14px;
                }
            }
        </style>
        <script id="goatafight-premium-paywall-js">
        (function () {
            'use strict';
            var AJAX_URL = <?php echo $ajax_url; ?>;
            var SESSION_NONCE = <?php echo $session_nonce; ?>;
            var LOGOUT_NONCE = <?php echo $logout_nonce; ?>;
            var GIFT_ACCESS = <?php echo $gift_access; ?>;
            var busy = false;
            var unlocked = false;

            function postForm(data) {
                var body = new URLSearchParams();
                Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
                return fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function (response) {
                    return response.json();
                });
            }

            function getBoxes() {
                return Array.prototype.slice.call(document.querySelectorAll('.goatafight-paywall[data-post-id]'));
            }
            function status(message, type) {
                getBoxes().forEach(function (box) {
                    var el = box.querySelector('[data-goatafight-status]');
                    if (!el) return;
                    el.textContent = message || '';
                    el.classList.remove('is-error', 'is-ok');
                    if (type) el.classList.add(type);
                });
            }
            function fetchPremiumContent(box) {
                return postForm({
                    action: 'goatafight_premium_content',
                    post_id: box.getAttribute('data-post-id'),
                    _ajax_nonce: box.getAttribute('data-content-nonce')
                }).then(function (json) {
                    if (!json || !json.success || !json.data || typeof json.data.html !== 'string') {
                        throw new Error(json && json.data && json.data.message ? json.data.message : 'Impossible de charger l’article complet.');
                    }
                    var wrapper = document.createElement('div');
                    wrapper.className = 'goatafight-premium-content';
                    wrapper.innerHTML = json.data.html;
                    box.replaceWith(wrapper);
                    wrapper.querySelectorAll('script').forEach(function (oldScript) {
                        var newScript = document.createElement('script');
                        Array.prototype.slice.call(oldScript.attributes).forEach(function (attr) {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        newScript.text = oldScript.text || oldScript.textContent || '';
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });
                });
            }
            function processResult(result) {
                if (GIFT_ACCESS || unlocked || busy || !getBoxes().length) return;
                if (!result) return;
                if (!result.checked) {
                    status('Impossible de vérifier l’abonnement Google pour le moment.', 'is-error');
                    return;
                }
                if (!result.premium) {
                    status('Accès Premium non reconnu. Vérifie le compte Google utilisé pour ton abonnement.', '');
                    return;
                }
                // A yes/no flag cannot authorize paid content on the server.
                // Require a Google-signed entitlement that PHP verifies cryptographically.
                if (!result.signedEntitlements) {
                    status('Abonnement détecté, mais Google ne transmet pas de preuve signée exploitable. Le déblocage sécurisé nécessite une configuration complémentaire.', 'is-error');
                    return;
                }
                busy = true;
                status('Abonnement confirmé. Ouverture de la suite de l’article…', 'is-ok');
                postForm({
                    action: 'goatafight_premium_session',
                    _ajax_nonce: SESSION_NONCE,
                    signed_entitlements: result.signedEntitlements
                }).then(function (session) {
                    if (!session || !session.success) {
                        throw new Error(session && session.data && session.data.message ? session.data.message : 'Vérification sécurisée de l’abonnement refusée.');
                    }
                    return Promise.all(getBoxes().map(fetchPremiumContent));
                }).then(function () {
                    unlocked = true;
                }).catch(function (err) {
                    status(err && err.message ? err.message : 'Erreur de chargement de l’article.', 'is-error');
                }).finally(function () {
                    busy = false;
                });
            }
            window.addEventListener('goatafight-premium-entitlement', function (event) {
                processResult(event.detail);
            });
            if (GIFT_ACCESS && getBoxes().length) {
                busy = true;
                status('Premium offert confirmé. Ouverture de l’article…', 'is-ok');
                Promise.all(getBoxes().map(fetchPremiumContent)).then(function () {
                    unlocked = true;
                }).catch(function (err) {
                    status(err && err.message ? err.message : 'Impossible de charger ton accès offert.', 'is-error');
                }).finally(function () {
                    busy = false;
                });
            } else {
                processResult(window.GoataFightPremiumResult);
            }
            document.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('[data-goatafight-refresh]') : null;
                if (!button) return;
                event.preventDefault();
                if (GIFT_ACCESS) {
                    status('Ton accès Premium offert est déjà confirmé.', 'is-ok');
                    return;
                }
                status('Nouvelle vérification de l’abonnement en cours…', '');
                if (typeof window.GoataFightPremiumRecheck === 'function') {
                    window.GoataFightPremiumRecheck();
                    processResult(window.GoataFightPremiumResult);
                } else {
                    status('Recharge la page pour relancer la vérification Google.', 'is-error');
                }
            });
            window.addEventListener('goatafight-premium-recheck-unavailable', function () {
                status('Actualise la page pour relancer la vérification Google.', '');
            });
        })();
        </script>
        <?php
    }

    /** Validate Google-issued JWT with Google's rotating public certificates. */
    private static function verify_google_signed_entitlements( $jwt ) {
        if ( ! function_exists( 'openssl_verify' ) || ! is_string( $jwt ) || strlen( $jwt ) > 32768 ) {
            return false;
        }
        $parts = explode( '.', $jwt );
        if ( 3 !== count( $parts ) ) {
            return false;
        }
        foreach ( $parts as $part ) {
            if ( '' === $part || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $part ) ) {
                return false;
            }
        }
        $header_json  = self::decode_base64url( $parts[0] );
        $payload_json = self::decode_base64url( $parts[1] );
        $signature    = self::decode_base64url( $parts[2] );
        if ( false === $header_json || false === $payload_json || false === $signature ) {
            return false;
        }
        $header  = json_decode( $header_json, true );
        $payload = json_decode( $payload_json, true );
        if ( ! is_array( $header ) || ! is_array( $payload ) || 'RS256' !== ( $header['alg'] ?? '' ) ) {
            return false;
        }
        $kid = $header['kid'] ?? '';
        if ( ! is_string( $kid ) || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/D', $kid ) ) {
            return false;
        }
        $now = time();
        if ( ( $payload['iss'] ?? '' ) !== 'subscribewithgoogle@system.gserviceaccount.com'
            || ! isset( $payload['exp'], $payload['iat'] )
            || ! is_numeric( $payload['exp'] ) || ! is_numeric( $payload['iat'] )
            || (int) $payload['exp'] <= $now || (int) $payload['iat'] > ( $now + 120 ) ) {
            return false;
        }
        $aud = $payload['aud'] ?? '';
        $allowed_audiences = array();
        foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
            $url_parts = wp_parse_url( $url );
            if ( is_array( $url_parts ) && isset( $url_parts['scheme'], $url_parts['host'] ) ) {
                $allowed_audiences[] = $url_parts['scheme'] . '://' . $url_parts['host'] . ( isset( $url_parts['port'] ) ? ':' . (int) $url_parts['port'] : '' );
            }
        }
        if ( ! is_string( $aud ) || ! in_array( rtrim( $aud, '/' ), array_unique( $allowed_audiences ), true ) ) {
            return false;
        }
        $entitlements = $payload['entitlements'] ?? array();
        $matched = false;
        if ( is_array( $entitlements ) ) {
            foreach ( $entitlements as $entitlement ) {
                if ( ! is_array( $entitlement ) || ! isset( $entitlement['products'] ) || ! is_array( $entitlement['products'] ) ) {
                    continue;
                }
                if ( in_array( self::product_id(), $entitlement['products'], true ) &&
                    ( ! isset( $entitlement['source'] ) || 'google:metering' !== $entitlement['source'] ) ) {
                    $matched = true;
                    break;
                }
            }
        }
        if ( ! $matched ) {
            return false;
        }
        $cert_url = 'https://www.googleapis.com/robot/v1/metadata/x509/subscribewithgoogle@system.gserviceaccount.com';
        $cache_key = 'goatafight_premium_google_certs_v1';
        $certs = get_transient( $cache_key );
        if ( ! is_array( $certs ) || ! isset( $certs[ $kid ] ) ) {
            $response = wp_remote_get( $cert_url, array( 'timeout' => 8, 'redirection' => 0, 'sslverify' => true ) );
            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return false;
            }
            $certs = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $certs ) || ! isset( $certs[ $kid ] ) ) {
                return false;
            }
            set_transient( $cache_key, $certs, HOUR_IN_SECONDS );
        }
        $cert = $certs[ $kid ];
        if ( ! is_string( $cert ) || strlen( $cert ) > 16384 ) {
            return false;
        }
        return 1 === openssl_verify( $parts[0] . '.' . $parts[1], $signature, $cert, OPENSSL_ALGO_SHA256 );
    }

    private static function decode_base64url( $value ) {
        $value = strtr( $value, '-_', '+/' );
        return base64_decode( $value . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
    }

    /* -------------------------------------------------------------------------
     * AJAX
     * ---------------------------------------------------------------------- */

    public static function ajax_create_premium_session() {
        check_ajax_referer( 'goatafight_premium_session' );

        $jwt = isset( $_POST['signed_entitlements'] ) ? (string) wp_unslash( $_POST['signed_entitlements'] ) : '';
        if ( ! self::verify_google_signed_entitlements( $jwt ) ) {
            wp_send_json_error( array( 'message' => 'Preuve d’abonnement Google absente, expirée ou non valide.' ), 403 );
        }

        self::set_access_cookie();

        nocache_headers();
        wp_send_json_success( array( 'expires_in' => self::ACCESS_TTL ) );
    }

    public static function ajax_clear_premium_session() {
        check_ajax_referer( 'goatafight_premium_logout' );
        self::clear_access_cookie();
        nocache_headers();
        wp_send_json_success();
    }

    public static function ajax_get_premium_content() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Article invalide.' ), 400 );
        }

        check_ajax_referer( 'goatafight_premium_content_' . $post_id );

        // L'accès offert est vérifié à chaque requête avec la session WordPress actuelle.
        // Aucun cookie JS ou indicateur client ne peut suffire à débloquer l'article.
        if ( ! self::current_user_has_server_access() ) {
            wp_send_json_error( array( 'message' => 'Accès Premium non validé ou expiré. Connecte-toi à ton compte WordPress si ton Premium t’a été offert.' ), 403 );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status || ! self::post_has_paywall( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Contenu Premium introuvable.' ), 404 );
        }

        $parts = self::split_raw_content( $post->post_content );
        if ( ! $parts ) {
            wp_send_json_error( array( 'message' => 'Séparateur Premium introuvable.' ), 404 );
        }

        // Rend uniquement la partie Premium avec les filtres WordPress habituels.
        $old_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $post );

        $premium_html = apply_filters( 'the_content', $parts['premium'] );

        wp_reset_postdata();
        if ( $old_post instanceof WP_Post ) {
            $GLOBALS['post'] = $old_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }

        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );

        wp_send_json_success(
            array(
                'html' => $premium_html,
            )
        );
    }

    /* -------------------------------------------------------------------------
     * SEO / Rank Math
     * ---------------------------------------------------------------------- */

    public static function filter_rank_math_json_ld( $data, $jsonld ) {
        if ( is_admin() || ! is_singular() ) {
            return $data;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || ! self::post_has_paywall( $post_id ) || ! is_array( $data ) ) {
            return $data;
        }

        foreach ( $data as $key => $entity ) {
            if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
                continue;
            }

            $types = (array) $entity['@type'];
            $match = array_intersect( $types, array( 'Article', 'NewsArticle', 'BlogPosting' ) );

            if ( empty( $match ) ) {
                continue;
            }

            $data[ $key ]['isAccessibleForFree'] = false;
            $data[ $key ]['hasPart'] = array(
                '@type'               => 'WebPageElement',
                'isAccessibleForFree' => false,
                'cssSelector'         => '.goatafight-premium-content',
            );
        }

        return $data;
    }
}

GoataFight_Premium::init();

// Fonction d'intégration pour d'autres plugins GoataFight (contrôle côté serveur).
if ( ! function_exists( 'goatafight_premium_has_access' ) ) {
    function goatafight_premium_has_access() {
        return GoataFight_Premium::current_user_has_server_access();
    }
}
