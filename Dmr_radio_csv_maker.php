<?php
/*
Plugin Name: DMR Radio CSV Master Base
Plugin URI: https://jj2yyk.forums.gr.jp/
Description: RadioID.netのデータを管理画面から取得・管理し、システム全体にユーザー情報を提供する中核（コア）プラグインです。
Version: 2.2.0
Author: JI2TAB / JJ2YYK
*/

if (!defined('ABSPATH')) exit;

class DMR_CSV_Maker_Master {
    private $upload_dir;
    private $source_csv;

    public function __construct() {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/dmr_csv_maker';
        $this->source_csv = $this->upload_dir . '/user.csv'; 

        add_action('init', [$this, 'init_plugin']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_force_update']);
        add_action('dmr_csv_daily_update_hook', [$this, 'update_source_csv']);
    }

    public function init_plugin() {
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
    }

    public function add_admin_menu() {
        // 設定メニューのアイコンをデータベースマークに変更
        add_menu_page(
            'DMR CSV Master', 
            'DMR CSV 管理', 
            'manage_options', 
            'dmr-csv-settings', 
            [$this, 'render_admin_page'],
            'dashicons-database', // ★アイコンを追加
            80
        );
    }

    // ★ ここから下が「立派なUI」を構成するコードです ★
    public function render_admin_page() {
        $file_exists = file_exists($this->source_csv);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline" style="margin-bottom: 10px;">
                <span class="dashicons dashicons-database" style="font-size: 28px; width: 28px; height: 28px; margin-top: 3px; margin-right: 8px;"></span>
                DMR Radio CSV Master コントロールパネル
            </h1>
            <hr class="wp-header-end">

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    
                    <div id="post-body-content">
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle" style="font-size: 16px;"><span><span class="dashicons dashicons-chart-area"></span> システムステータス</span></h2>
                            </div>
                            <div class="inside">
                                <table class="form-table" style="margin-top: 0;">
                                    <tr>
                                        <th scope="row" style="padding: 15px 10px;">データベース状態</th>
                                        <td style="padding: 15px 10px;">
                                            <?php if ($file_exists): ?>
                                                <span style="background: #e5f5fa; color: #007cba; padding: 5px 10px; border-radius: 3px; font-weight: bold;">✅ 正常稼働中</span>
                                            <?php else: ?>
                                                <span style="background: #fbeaea; color: #d63638; padding: 5px 10px; border-radius: 3px; font-weight: bold;">❌ データ未取得</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row" style="padding: 15px 10px;">最終更新日時</th>
                                        <td style="padding: 15px 10px;">
                                            <?php 
                                            if ($file_exists) {
                                                echo '<strong>' . date('Y年m月d日 H:i:s', filemtime($this->source_csv)) . '</strong>';
                                                echo ' <span style="color: #666;">(' . round(filesize($this->source_csv) / 1024 / 1024, 2) . ' MB)</span>';
                                            } else {
                                                echo '<span style="color:#d63638;">データがありません。手動取得を実行してください。</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row" style="padding: 15px 10px;">保存先パス</th>
                                        <td style="padding: 15px 10px;"><code style="background: #f0f0f1; padding: 3px 5px;"><?php echo esc_html($this->source_csv); ?></code></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle" style="font-size: 16px;"><span><span class="dashicons dashicons-update-alt"></span> データの強制取得</span></h2>
                            </div>
                            <div class="inside">
                                <p style="font-size: 14px; color: #555;">
                                    バックグラウンドで1日1回自動更新されていますが、すぐに最新データが必要な場合は以下のボタンから手動で RadioID.net に同期できます。
                                </p>
                                <form method="post" action="" style="margin-top: 15px;">
                                    <?php wp_nonce_field('dmr_force_update_action', 'dmr_admin_nonce'); ?>
                                    <input type="hidden" name="dmr_admin_update" value="1">
                                    <?php submit_button('🔄 今すぐ RadioID.net からデータを取得', 'primary large', 'submit', false); ?>
                                </form>
                                <p style="color:#888; font-size: 12px; margin-top: 10px;">※取得にはサーバー環境により十数秒かかる場合があります。ボタンは1回だけ押してお待ちください。</p>
                            </div>
                        </div>
                    </div>

                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle"><span><span class="dashicons dashicons-info"></span> プラグイン情報</span></h2>
                            </div>
                            <div class="inside">
                                <p><strong>バージョン:</strong> 2.2.0</p>
                                <p><strong>開発者:</strong> JI2TAB / JJ2YYK</p>
                                <hr>
                                <p style="color: #666; font-size: 13px;">
                                    このプラグインは、他のプラグイン（Hotspot 受信システム等）がコールサインからユーザー名を割り出すための「コア基盤」として動作します。
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    // 取得処理 (変更なし)
    public function handle_force_update() {
        if (isset($_POST['dmr_admin_update']) && current_user_can('manage_options')) {
            if (!wp_verify_nonce($_POST['dmr_admin_nonce'], 'dmr_force_update_action')) {
                wp_die('セキュリティチェックに失敗しました。');
            }

            $result = $this->update_source_csv();
            
            if ($result === true) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible" style="border-left-color: #00a32a;"><p><strong>✅ 最新のCSVデータを取得し、データベースを更新しました！</strong></p></div>';
                });
            } else {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>❌ 取得失敗:</strong> ' . esc_html($result) . '</p></div>';
                });
            }
        }
    }

    public function update_source_csv() {
        $v = date('Y-m-d_His');
        $url = "https://radioid.net/static/user.csv?v={$v}"; 
        
        $response = wp_remote_get($url, [
            'timeout'    => 300,
            'stream'     => true,
            'filename'   => $this->source_csv . '.tmp',
            'user-agent' => 'DMR-CSV-Maker-v2.2.0; JI2TAB'
        ]);

        if (is_wp_error($response)) return $response->get_error_message();

        $tmp_file = $this->source_csv . '.tmp';
        if (!file_exists($tmp_file)) return "一時ファイルの作成に失敗しました。";

        $handle = fopen($tmp_file, 'r');
        $first_line = fgets($handle);
        fclose($handle);

        if (strpos($first_line, '<html') !== false || empty($first_line)) {
            unlink($tmp_file);
            return "RadioID.netが正しいCSVを返しませんでした。";
        }

        if (file_exists($this->source_csv)) unlink($this->source_csv);
        rename($tmp_file, $this->source_csv);

        return true;
    }
}
new DMR_CSV_Maker_Master();

// =========================================================================
// 他プラグイン用 API関数（Hotspot Ingestなどから利用）
// =========================================================================
if (!function_exists('dmr_get_user_info')) {
    function dmr_get_user_info($radio_id) {
        $upload = wp_upload_dir();
        $source_csv = $upload['basedir'] . '/dmr_csv_maker/user.csv';

        if (!file_exists($source_csv)) return false;

        $radio_id = trim((string)$radio_id);

        if (($handle = fopen($source_csv, "r")) !== FALSE) {
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (isset($data[0]) && trim($data[0]) === $radio_id) {
                    fclose($handle);
                    return [
                        'radio_id'   => trim($data[0]),
                        'callsign'   => trim($data[1] ?? ''),
                        'first_name' => trim($data[2] ?? ''),
                        'last_name'  => trim($data[3] ?? ''),
                        'full_name'  => trim(($data[2] ?? '') . ' ' . ($data[3] ?? ''))
                    ];
                }
            }
            fclose($handle);
        }
        return false;
    }
}

// WP-Cron スケジュール設定
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('dmr_csv_daily_update_hook')) {
        wp_schedule_event(time(), 'daily', 'dmr_csv_daily_update_hook');
    }
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('dmr_csv_daily_update_hook');
});
