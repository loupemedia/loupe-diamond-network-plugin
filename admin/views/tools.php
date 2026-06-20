<?php
/**
 * Admin tools view.
 *
 * @var string|null $version
 * @var string      $environment
 * @var string|null $site_id
 * @var string      $saved_site_id
 * @var array       $site_choices
 * @var string      $resolution_source
 * @var string      $current_host
 * @var string      $notice
 * @var string      $notice_type
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Loupe Diamond Network', 'loupe-diamond-network'); ?></h1>

    <?php if ($notice !== '') : ?>
        <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <h2><?php esc_html_e('Which site is this?', 'loupe-diamond-network'); ?></h2>
    <p><?php esc_html_e('On Kinsta staging the URL often does not match the live domain — pick the site here once (no wp-config edit needed).', 'loupe-diamond-network'); ?></p>

    <form method="post" style="max-width:640px;margin-bottom:2rem;">
        <?php wp_nonce_field(LDN_Admin::NONCE_SITE); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ldn_site_id"><?php esc_html_e('Site', 'loupe-diamond-network'); ?></label></th>
                <td>
                    <select name="ldn_site_id" id="ldn_site_id" class="regular-text">
                        <option value="" <?php selected($saved_site_id, ''); ?>>
                            <?php esc_html_e('Auto-detect from domain (production)', 'loupe-diamond-network'); ?>
                        </option>
                        <?php foreach ($site_choices as $choice_id => $label) : ?>
                            <option value="<?php echo esc_attr($choice_id); ?>" <?php selected($saved_site_id, $choice_id); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: HTTP host, 2: resolution source label */
                            esc_html__('Current host: %1$s. Resolved via: %2$s.', 'loupe-diamond-network'),
                            esc_html($current_host !== '' ? $current_host : '—'),
                            esc_html($resolution_source !== '' ? $resolution_source : '—')
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>
        <p>
            <button type="submit" name="ldn_save_site" class="button button-primary">
                <?php esc_html_e('Save site', 'loupe-diamond-network'); ?>
            </button>
        </p>
    </form>

    <h2><?php esc_html_e('Status', 'loupe-diamond-network'); ?></h2>
    <table class="widefat" style="max-width:640px;margin-bottom:1.5rem;">
        <tbody>
            <tr><th><?php esc_html_e('Effective site ID', 'loupe-diamond-network'); ?></th><td><code><?php echo esc_html($site_id !== null ? $site_id : '—'); ?></code></td></tr>
            <tr><th><?php esc_html_e('Environment', 'loupe-diamond-network'); ?></th><td><code><?php echo esc_html($environment); ?></code></td></tr>
            <tr><th><?php esc_html_e('Cached rollout version', 'loupe-diamond-network'); ?></th><td><?php echo esc_html($version !== null ? (string) $version : '—'); ?></td></tr>
        </tbody>
    </table>

    <h2><?php esc_html_e('Pull rollout', 'loupe-diamond-network'); ?></h2>
    <p><?php esc_html_e('Force this install to pull the latest rollout config and S3 artefact data.', 'loupe-diamond-network'); ?></p>

    <form method="post">
        <?php wp_nonce_field(LDN_Admin::NONCE_PULL); ?>
        <p>
            <button type="submit" name="ldn_pull_rollout" class="button button-secondary">
                <?php esc_html_e('Pull now (rollout + S3 caches)', 'loupe-diamond-network'); ?>
            </button>
        </p>
        <p class="description">
            <?php esc_html_e('Clears the rollout transient (~5 min TTL), artefact caches, and the config bundle cache, then re-fetches rollout from S3. Permalinks flush if the rollout version changed.', 'loupe-diamond-network'); ?>
        </p>
    </form>
</div>
