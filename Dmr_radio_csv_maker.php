<?php
/*
Plugin Name: DMR Radio CSV Maker (Standalone/Lightweight)
Plugin URI: https://jj2yyk.forums.gr.jp/
Description: RadioID.netのデータを軽量に加工・ダウンロードする独立版。
Version: 1.5.4
Author: JI2TAB / JJ2YYK
*/

if (!defined('ABSPATH')) exit;

class DMR_CSV_Maker_Standalone {
    private $upload_dir;
    private $cache_dir;
    private $source_csv;
    private $log_file;

    public function __construct() {
        $upload = wp_upload_dir();
        // データの保存場所を専用フォルダに隔離
        $this->upload_dir = $upload['basedir'] . '/dmr_csv_maker';
        $this->cache_dir  = $this->upload_dir . '/_cache';
        $this->source_csv = $this->upload_dir . '/user.csv'; 
        $this->log_file   = $this->upload_dir . '/download_log.csv';

        add_action('init', [$this, 'init_plugin']);
        add_shortcode('dmr_csv_maker', [$this, 'render_shortcode']);
        add_action('wp_loaded', [$this, 'handle_requests']);
    }

    public function init_plugin() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            file_put_contents($this->cache_dir . '/.htaccess', "Deny from all");
        }
    }

    /**
     * 最新データの取得
     * RadioIDのポリシーに基づき、独自のUser-Agentを設定し出典を明確にします。
     */
    public function update_source_csv() {
        // 動的なタイムスタンプを付与してキャッシュを回避
        $v = date('Y-m-d_His');
        $url = "https://radioid.net/static/user.csv?v={$v}"; 
        
        $response = wp_remote_get($url, [
            'timeout'    => 300,
            'stream'     => true,
            'filename'   => $this->source_csv . '.tmp', // 一時ファイルとして保存
            'user-agent' => 'DMR-CSV-Maker-v1.5.4; JI2TAB; https://jj2yyk.forums.gr.jp/' // ポリシー準拠のUA
        ]);

        if (is_wp_error($response)) {
            return "通信エラー: " . $response->get_error_message();
        }

        $tmp_file = $this->source_csv . '.tmp';
        if (!file_exists($tmp_file)) return "エラー: ファイルの作成に失敗しました。";

        // 内容が404エラー（HTML）でないかチェック
        $handle = fopen($tmp_file, 'r');
        $first_line = fgets($handle);
        fclose($handle);

        if (strpos($first_line, '<html') !== false || strpos($first_line, '404') !== false || empty($first_line)) {
            unlink($tmp_file);
            return "取得失敗: RadioID.net側でエラーが発生しました。URLの有効期限を確認してください。";
        }

        // 正常なら本番用ファイルに置換
        if (file_exists($this->source_csv)) unlink($this->source_csv);
        rename($tmp_file, $this->source_csv);

        return true;
    }

    // 認証処理（1行ずつスキャンしてメモリ消費を抑える）
    private function authenticate_user($callsign, $dmr_id) {
        if (!file_exists($this->source_csv)) return false;

        $callsign = strtoupper(trim($callsign));
        $dmr_id = trim($dmr_id);

        if (($handle = fopen($this->source_csv, "r")) !== FALSE) {
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (isset($data[0]) && isset($data[1])) {
                    if (trim($data[0]) === $dmr_id && strtoupper(trim($data[1])) === $callsign) {
                        fclose($handle);
                        return $data;
                    }
                }
            }
            fclose($handle);
        }
        return false;
    }

    // CSV生成（ストリーム処理）
    private function generate_output_csv($type, $country = 'all') {
        $timestamp = date('YmdHis');
        $country_slug = ($country === 'all') ? 'Global' : preg_replace('/[^a-zA-Z0-9]/', '', $country);
        $filename = "dmr_{$type}_{$country_slug}_{$timestamp}.csv";
        $cache_path = $this->cache_dir . "/{$type}_{$country_slug}.csv";

        // 24時間のキャッシュを有効に（サーバー負荷低減のため）
        if (file_exists($cache_path) && (time() - filemtime($cache_path) < 86400)) {
            return ['path' => $cache_path, 'name' => $filename];
        }

        $in = fopen($this->source_csv, "r");
        $out = fopen($cache_path, "w");

        if ($type === 'h1') {
            // H1用ヘッダー
            fputcsv($out, ['Contacts Alias', 'Call Type', 'Call ID', 'City', 'Province', 'Country']);
        } else {
            // 標準ヘッダー
            fputcsv($out, ['RADIO_ID', 'CALLSIGN', 'FIRST_NAME', 'LAST_NAME', 'CITY', 'STATE', 'COUNTRY']);
        }

        while (($row = fgetcsv($in)) !== FALSE) {
            if ($country !== 'all' && (!isset($row[6]) || strpos($row[6], $country) === false)) continue;

            if ($type === 'h1') {
                // H1用成形ロジック
                $alias = "{$row[1]} {$row[0]}";
                $name  = mb_strimwidth($row[2] ?? '', 0, 16);
                $city  = mb_strimwidth($row[4] ?? '', 0, 16);
                $region = ($row[6] ?? '') . ' ' . ($row[5] ?? '');
                fputcsv($out, [$alias, 'Private Call', $row[0], $name, $city, $region]);
            } else {
                fputcsv($out, $row);
            }
        }
        fclose($in);
        fclose($out);

        return ['path' => $cache_path, 'name' => $filename];
    }

    public function handle_requests() {
        if (isset($_POST['dmr_force_update']) && current_user_can('manage_options')) {
            $result = $this->update_source_csv();
            set_transient('dmr_msg', ($result === true ? 'データの取得に成功しました！' : $result), 30);
            wp_redirect(remove_query_arg('dmr_updated'));
            exit;
        }

        if (isset($_POST['dmr_action']) && $_POST['dmr_action'] === 'download') {
            if (!wp_verify_nonce($_POST['dmr_nonce'], 'dmr_download')) wp_die('Security Check Failed');

            $callsign = sanitize_text_field($_POST['callsign']);
            $dmr_id   = sanitize_text_field($_POST['dmr_id']);
            $type     = ($_POST['type'] === 'h1') ? 'h1' : 'std';
            $country  = sanitize_text_field($_POST['country']);

            if ($this->authenticate_user($callsign, $dmr_id)) {
                $file_info = $this->generate_output_csv($type, $country);
                
                // ログ保存
                $log_fp = fopen($this->log_file, 'a');
                fputcsv($log_fp, [date('Y-m-d H:i:s'), $callsign, $dmr_id, $country, $type, $_SERVER['REMOTE_ADDR']]);
                fclose($log_fp);

                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $file_info['name'] . '"');
                header('Content-Length: ' . filesize($file_info['path']));
                readfile($file_info['path']);
                exit;
            } else {
                set_transient('dmr_msg', '認証に失敗しました。DMRユーザー登録情報と一致しません。', 30);
                wp_redirect(remove_query_arg('dmr_error'));
                exit;
            }
        }
    }

    public function render_shortcode() {
        $msg = get_transient('dmr_msg');
        delete_transient('dmr_msg');
        ob_start();
        ?>
        <div class="dmr-maker-v154" style="border:1px solid #ddd; padding:20px; border-radius:10px; background:#fff; max-width:500px; font-family: sans-serif;">
            <h3 style="margin-top:0;">DMR Radio CSV Maker</h3>
            <p style="font-size:0.8em; color:#666; margin-bottom:15px;">
                Source: <a href="https://www.radioid.net/" target="_blank" rel="noopener">RadioID.net</a> Data
            </p>

            <?php if ($msg): ?>
                <p style="padding:10px; background:#fff9e6; border-left:4px solid #ffb900; font-size:0.9em;">
                    <?php echo esc_html($msg); ?>
                </p>
            <?php endif; ?>

            <?php if (current_user_can('manage_options')): ?>
                <form method="post" style="margin-bottom:20px; padding:10px; background:#f9f9f9; border:1px dashed #ccc;">
                    <span style="font-size:0.8em; color:#666;">管理者用: </span>
                    <button type="submit" name="dmr_force_update" style="font-size:0.8em; cursor:pointer;">最新データの再取得</button>
                </form>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('dmr_download', 'dmr_nonce'); ?>
                <input type="hidden" name="dmr_action" value="download">
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:0.9em; font-weight:bold;">Callsign:</label>
                    <input type="text" name="callsign" required placeholder="例: JI2TAB" style="width:100%; padding:8px; border:1px solid #ccc;">
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:0.9em; font-weight:bold;">DMR ID:</label>
                    <input type="text" name="dmr_id" required placeholder="例: 4400000" style="width:100%; padding:8px; border:1px solid #ccc;">
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:0.9em; font-weight:bold;">Country Filter:</label>
                    <select name="country" style="width:100%; padding:8px; border:1px solid #ccc;">
                        <option value="all">Global (All Countries)</option>
                        <option value="Japan" selected>Japan Only</option>
                    </select>
                </div>

                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="submit" name="type" value="std" style="flex:1; padding:10px; cursor:pointer;">通常CSV</button>
                    <button type="submit" name="type" value="h1" style="flex:1; padding:10px; background:#0073aa; color:#fff; border:none; cursor:pointer; font-weight:bold;">H1用CSV</button>
                </div>
            </form>
            
            <p style="font-size:0.75em; color:#888; text-align:center; margin-top:20px; line-height:1.4;">
                データ更新日: <?php 
                    if (file_exists($this->source_csv)) {
                        $fsize = round(filesize($this->source_csv) / 1024 / 1024, 2);
                        echo date('Y/m/d H:i', filemtime($this->source_csv)) . " ({$fsize} MB)";
                    } else {
                        echo '<span style="color:red; font-weight:bold;">未取得（更新ボタンを押してください）</span>';
                    }
                ?>
                <br>
                <span style="font-size:0.9em;">※アマチュア無線のプログラミング利用を目的としています。</span>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
new DMR_CSV_Maker_Standalone();
