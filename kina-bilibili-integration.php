<?php
/**
 * Plugin Name: Kina Bilibili Integration
 * Description: B站数据集成插件，支持个人资料、视频、追番、直播状态展示
 * Version: 1.0.5
 * Author: kina漫记
 * Text Domain: kina-bilibili
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KINA_BILI_VERSION', '1.0.5');
define('KINA_BILI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KINA_BILI_PLUGIN_URL', plugin_dir_url(__FILE__));

class Kina_Bilibili_Integration {

    private static $instance = null;
    private $option_name = 'kina_bilibili_settings';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
        add_action('wp_ajax_kina_bili_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_kina_bili_refresh_live', array($this, 'ajax_refresh_live'));

        // 定时任务
        add_action('kina_bili_refresh_live_cron', array($this, 'cron_refresh_live'));
        if (!wp_next_scheduled('kina_bili_refresh_live_cron')) {
            wp_schedule_event(time(), 'every_five_minutes', 'kina_bili_refresh_live_cron');
        }

        // 短代码
        add_shortcode('kina_bilibili_profile', array($this, 'shortcode_profile'));
        
        add_shortcode('kina_bilibili_bangumi', array($this, 'shortcode_bangumi'));
        add_shortcode('kina_bilibili_status', array($this, 'shortcode_status'));

        // 自定义定时间隔
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    }

    public function add_cron_intervals($schedules) {
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display' => '每5分钟'
        );
        return $schedules;
    }

    public function add_admin_menu() {
        add_menu_page(
            'B站集成设置',
            'B站集成',
            'manage_options',
            'kina-bilibili',
            array($this, 'admin_page'),
            'dashicons-video-alt3',
            30
        );
    }

    public function admin_scripts($hook) {
        if ($hook !== 'toplevel_page_kina-bilibili') return;
        wp_enqueue_style('kina-bili-admin', KINA_BILI_PLUGIN_URL . 'assets/css/admin.css', array(), KINA_BILI_VERSION);
        wp_enqueue_script('kina-bili-admin', KINA_BILI_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), KINA_BILI_VERSION, true);
        wp_localize_script('kina-bili-admin', 'kinaBiliAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kina_bili_nonce')
        ));
    }

    public function frontend_scripts() {
        wp_enqueue_style('kina-bili-style', KINA_BILI_PLUGIN_URL . 'assets/css/bili-style.css', array(), KINA_BILI_VERSION);
        wp_enqueue_script('kina-bili-js', KINA_BILI_PLUGIN_URL . 'assets/js/bili.js', array('jquery'), KINA_BILI_VERSION, true);
        wp_localize_script('kina-bili-js', 'kinaBili', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kina_bili_nonce'),
            'uid' => $this->get_uid()
        ));
    }

    private function get_settings() {
        return get_option($this->option_name, array('uid' => ''));
    }

    private function get_uid() {
        $settings = $this->get_settings();
        return isset($settings['uid']) ? sanitize_text_field($settings['uid']) : '';
    }

    // ========== API请求 ==========
    private function fetch_api($url, $cache_key, $cache_time = 600) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer' => 'https://space.bilibili.com'
            )
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['data']) && $data['code'] == 0) {
            set_transient($cache_key, $data['data'], $cache_time);
            return $data['data'];
        }

        return null;
    }

    private function get_user_info($uid) {
        return $this->fetch_api(
            "https://api.bilibili.com/x/web-interface/card?mid={$uid}",
            "kina_bili_user_{$uid}",
            600
        );
    }

    private function get_live_status($uid) {
        return $this->fetch_api(
            "https://api.live.bilibili.com/room/v1/Room/getRoomInfoOld?mid={$uid}",
            "kina_bili_live_{$uid}",
            120
        );
    }



    private function get_bangumi($uid) {
        return $this->fetch_api(
            "https://api.bilibili.com/x/space/bangumi/follow/list?vmid={$uid}&type=1&pn=1&ps=12",
            "kina_bili_bangumi_{$uid}",
            3600
        );
    }

    // ========== 数字格式化 ==========
    private function format_number($num) {
        if (!is_numeric($num)) return '0';
        $num = floatval($num);
        if ($num >= 10000) {
            return round($num / 10000, 1) . '万';
        }
        return number_format($num);
    }

    // ========== 时间格式化 ==========
    private function format_time($timestamp) {
        if (empty($timestamp)) return '未知时间';
        $time = is_numeric($timestamp) ? intval($timestamp) : strtotime($timestamp);
        $diff = time() - $time;

        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . '分钟前';
        if ($diff < 86400) return floor($diff / 3600) . '小时前';
        if ($diff < 604800) return floor($diff / 86400) . '天前';
        return date('Y-m-d', $time);
    }

    // ========== 直播状态 ==========
    private function get_live_info($uid) {
        $live = $this->get_live_status($uid);
        $is_live = false;
        $live_title = '';
        $room_id = '';

        if ($live && isset($live['data'])) {
            $data = $live['data'];
            if (isset($data['liveStatus']) && $data['liveStatus'] == 1) {
                $is_live = true;
                $live_title = isset($data['title']) ? $data['title'] : '直播中';
                $room_id = isset($data['roomid']) ? $data['roomid'] : '';
            }
        }

        return array(
            'is_live' => $is_live,
            'title' => $live_title,
            'room_id' => $room_id
        );
    }

    // ========== 短代码：个人资料 ==========
    public function shortcode_profile($atts) {
        $uid = $this->get_uid();
        if (empty($uid)) {
            return '<div class="kina-bili-error">请在后台设置B站UID</div>';
        }

        $user = $this->get_user_info($uid);
        $live = $this->get_live_info($uid);

        if (!$user || !isset($user['card'])) {
            return '<div class="kina-bili-error">无法获取用户信息，请检查UID是否正确或B站隐私设置</div>';
        }

        $card = $user['card'];
        $space = isset($user['space']) ? $user['space'] : array();

        $name = isset($card['name']) ? esc_html($card['name']) : '未知用户';
        $face = isset($card['face']) ? esc_url($card['face']) : '';
        $sign = isset($card['sign']) ? esc_html($card['sign']) : '这个人很懒，什么都没有写~';
        $level = isset($card['level_info']['current_level']) ? intval($card['level_info']['current_level']) : 0;
        $fans = isset($card['fans']) ? $this->format_number($card['fans']) : '0';
        $following = isset($card['attention']) ? $this->format_number($card['attention']) : '0';
        $archive = isset($space['archive_count']) ? $this->format_number($space['archive_count']) : '0';

        $live_badge = '';
        $live_status_html = '';
        if ($live['is_live']) {
            $live_badge = '<span class="kina-bili-live-badge">LIVE</span>';
            $live_status_html = '<div class="kina-bili-live-status live-on">🔴 直播中 - ' . esc_html($live['title']) . '</div>';
        } else {
            $live_status_html = '<div class="kina-bili-live-status live-off">⚫ 未直播</div>';
        }

        $level_stars = str_repeat('★', $level) . str_repeat('☆', 6 - $level);

        ob_start();
        ?>
        <div class="kina-bili-profile-card">
            <div class="kina-bili-profile-header">
                <div class="kina-bili-avatar-wrap">
                    <img src="<?php echo $face; ?>" alt="<?php echo $name; ?>" class="kina-bili-avatar" referrerpolicy="no-referrer" onerror="this.src='https://i0.hdslb.com/bfs/face/member/noface.jpg'">
                    <?php echo $live_badge; ?>
                </div>
                <div class="kina-bili-profile-info">
                    <h3 class="kina-bili-name"><?php echo $name; ?></h3>
                    <div class="kina-bili-level">等级 <?php echo $level; ?> <span class="kina-bili-stars"><?php echo $level_stars; ?></span></div>
                    <?php echo $live_status_html; ?>
                </div>
            </div>
            <div class="kina-bili-profile-stats">
                <div class="kina-bili-stat-item">
                    <span class="kina-bili-stat-num"><?php echo $following; ?></span>
                    <span class="kina-bili-stat-label">关注</span>
                </div>
                <div class="kina-bili-stat-item">
                    <span class="kina-bili-stat-num"><?php echo $fans; ?></span>
                    <span class="kina-bili-stat-label">粉丝</span>
                </div>
                <div class="kina-bili-stat-item">
                    <span class="kina-bili-stat-num"><?php echo $archive; ?></span>
                    <span class="kina-bili-stat-label">投稿</span>
                </div>
            </div>
            <div class="kina-bili-sign"><?php echo nl2br($sign); ?></div>
            <div class="kina-bili-profile-footer">
                <a href="https://space.bilibili.com/<?php echo esc_attr($uid); ?>" target="_blank" class="kina-bili-btn">访问B站主页 →</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }



    // ========== 短代码：追番列表 ==========
    public function shortcode_bangumi($atts) {
        $uid = $this->get_uid();
        if (empty($uid)) {
            return '<div class="kina-bili-error">请在后台设置B站UID</div>';
        }

        $bangumi = $this->get_bangumi($uid);

        if (!$bangumi || !isset($bangumi['list']) || empty($bangumi['list'])) {
            return '<div class="kina-bili-error">暂无追番数据，请检查B站隐私设置是否允许公开追番列表</div>';
        }

        $list = $bangumi['list'];

        ob_start();
        ?>
        <div class="kina-bili-section">
            <h3 class="kina-bili-section-title">📺 追番列表</h3>
            <div class="kina-bili-bangumi-grid">
                <?php foreach ($list as $item): ?>
                <?php
                    $title = isset($item['title']) ? esc_html($item['title']) : '未知番剧';
                    $cover = isset($item['cover']) ? esc_url($item['cover']) : '';
                    $season_id = isset($item['season_id']) ? esc_attr($item['season_id']) : '';
                    $total = isset($item['total_count']) ? intval($item['total_count']) : 0;
                    $progress = isset($item['progress']) ? esc_html($item['progress']) : '';
                    $area = isset($item['areas'][0]['name']) ? esc_html($item['areas'][0]['name']) : '';
                    $badge = isset($item['badge']) ? esc_html($item['badge']) : '';
                    $evaluate = isset($item['evaluate']) ? esc_html(mb_substr($item['evaluate'], 0, 50)) : '';
                ?>
                <a href="https://www.bilibili.com/bangumi/play/ss<?php echo $season_id; ?>" target="_blank" class="kina-bili-bangumi-card">
                    <div class="kina-bili-bangumi-cover">
                        <img src="<?php echo $cover; ?>" alt="<?php echo $title; ?>" loading="lazy" referrerpolicy="no-referrer" onerror="this.src='https://i0.hdslb.com/bfs/bangumi/placeholder.jpg'">
                        <?php if ($badge): ?>
                        <span class="kina-bili-bangumi-badge"><?php echo $badge; ?></span>
                        <?php endif; ?>
                        <?php if ($progress): ?>
                        <span class="kina-bili-bangumi-progress"><?php echo $progress; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="kina-bili-bangumi-info">
                        <h4 class="kina-bili-bangumi-title"><?php echo $title; ?></h4>
                        <div class="kina-bili-bangumi-meta">
                            <?php if ($area): ?><span><?php echo $area; ?></span><?php endif; ?>
                            <?php if ($total > 0): ?><span>全<?php echo $total; ?>集</span><?php endif; ?>
                        </div>
                        <?php if ($evaluate): ?>
                        <p class="kina-bili-bangumi-desc"><?php echo $evaluate; ?>...</p>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ========== 短代码：迷你状态 ==========
    public function shortcode_status($atts) {
        $uid = $this->get_uid();
        if (empty($uid)) {
            return '<div class="kina-bili-error">请在后台设置B站UID</div>';
        }

        $user = $this->get_user_info($uid);
        $live = $this->get_live_info($uid);

        if (!$user || !isset($user['card'])) {
            return '<div class="kina-bili-error">数据获取失败</div>';
        }

        $card = $user['card'];
        $name = isset($card['name']) ? esc_html($card['name']) : '未知';
        $face = isset($card['face']) ? esc_url($card['face']) : '';
        $fans = isset($card['fans']) ? $this->format_number($card['fans']) : '0';

        $live_badge = '';
        $live_text = '⚫ 未直播';
        $live_class = 'live-off';
        if ($live['is_live']) {
            $live_badge = '<span class="kina-bili-mini-live-badge">LIVE</span>';
            $live_text = '🔴 直播中 - ' . esc_html($live['title']);
            $live_class = 'live-on';
        }

        ob_start();
        ?>
        <div class="kina-bili-mini-status" data-uid="<?php echo esc_attr($uid); ?>">
            <div class="kina-bili-mini-avatar-wrap">
                <img src="<?php echo $face; ?>" alt="<?php echo $name; ?>" class="kina-bili-mini-avatar" referrerpolicy="no-referrer" onerror="this.src='https://i0.hdslb.com/bfs/face/member/noface.jpg'">
                <?php echo $live_badge; ?>
            </div>
            <div class="kina-bili-mini-info">
                <span class="kina-bili-mini-name"><?php echo $name; ?></span>
                <span class="kina-bili-mini-fans"><?php echo $fans; ?> 粉丝</span>
                <span class="kina-bili-mini-live <?php echo $live_class; ?>"><?php echo $live_text; ?></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ========== AJAX：测试API ==========
    public function ajax_test_api() {
        check_ajax_referer('kina_bili_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }

        $uid = isset($_POST['uid']) ? sanitize_text_field($_POST['uid']) : '';
        if (empty($uid) || !is_numeric($uid)) {
            wp_send_json_error('请输入有效的UID');
        }

        // 清除缓存强制刷新
        delete_transient("kina_bili_user_{$uid}");
        delete_transient("kina_bili_live_{$uid}");

        $user = $this->get_user_info($uid);
        $live = $this->get_live_info($uid);

        if (!$user || !isset($user['card'])) {
            wp_send_json_error('无法获取用户信息，请检查UID是否正确，或该用户设置了隐私保护');
        }

        $card = $user['card'];

        wp_send_json_success(array(
            'name' => isset($card['name']) ? $card['name'] : '未知',
            'level' => isset($card['level_info']['current_level']) ? $card['level_info']['current_level'] : 0,
            'fans' => isset($card['fans']) ? $this->format_number($card['fans']) : '0',
            'is_live' => $live['is_live'],
            'live_title' => $live['title'],
            'face' => isset($card['face']) ? $card['face'] : ''
        ));
    }

    // ========== AJAX：刷新直播状态 ==========
    public function ajax_refresh_live() {
        check_ajax_referer('kina_bili_nonce', 'nonce');

        $uid = isset($_POST['uid']) ? sanitize_text_field($_POST['uid']) : '';
        if (empty($uid)) {
            wp_send_json_error('UID为空');
        }

        // 清除缓存强制刷新
        delete_transient("kina_bili_live_{$uid}");
        $live = $this->get_live_info($uid);

        wp_send_json_success(array(
            'is_live' => $live['is_live'],
            'title' => $live['title'],
            'room_id' => $live['room_id']
        ));
    }

    // ========== 定时任务 ==========
    public function cron_refresh_live() {
        $uid = $this->get_uid();
        if (empty($uid)) return;

        delete_transient("kina_bili_live_{$uid}");
        $this->get_live_info($uid);
    }

    // ========== 后台页面 ==========
    public function admin_page() {
        $settings = $this->get_settings();
        $uid = isset($settings['uid']) ? esc_attr($settings['uid']) : '';

        if (isset($_POST['kina_bili_save']) && check_admin_referer('kina_bili_save')) {
            $new_uid = isset($_POST['uid']) ? sanitize_text_field($_POST['uid']) : '';
            update_option($this->option_name, array('uid' => $new_uid));
            $uid = esc_attr($new_uid);
            echo '<div class="notice notice-success"><p>设置已保存</p></div>';
        }
        ?>
        <div class="wrap kina-bili-admin">
            <div class="kina-bili-admin-header">
                <h1>🎬 Kina Bilibili Integration</h1>
                <p>B站数据集成插件 - 在WordPress中展示你的B站动态</p>
            </div>

            <div class="kina-bili-admin-content">
                <!-- 短代码指南 -->
                <div class="kina-bili-section-card">
                    <h2>📋 短代码使用指南</h2>
                    <div class="kina-bili-shortcode-grid">
                        <div class="kina-bili-code-card">
                            <code>[kina_bilibili_profile]</code>
                            <h4>完整个人资料卡片</h4>
                            <ul>
                                <li>头像、昵称、等级</li>
                                <li>粉丝/关注/投稿数</li>
                                <li>直播状态实时显示</li>
                                <li>个性签名</li>
                            </ul>
                            <span class="kina-bili-tag">适用于：关于页面、侧边栏</span>
                        </div>

                        <div class="kina-bili-code-card">
                            <code>[kina_bilibili_bangumi]</code>
                            <h4>追番列表</h4>
                            <ul>
                                <li>最多12部追番</li>
                                <li>地区、集数信息</li>
                                <li>观看进度</li>
                                <li>封面3:4竖屏</li>
                            </ul>
                            <span class="kina-bili-tag">适用于：兴趣展示页</span>
                        </div>
                        <div class="kina-bili-code-card">
                            <code>[kina_bilibili_status]</code>
                            <h4>迷你状态栏</h4>
                            <ul>
                                <li>紧凑布局</li>
                                <li>实时直播状态</li>
                                <li>粉丝数显示</li>
                                <li>每30秒自动刷新</li>
                            </ul>
                            <span class="kina-bili-tag">适用于：页脚、侧边栏</span>
                        </div>
                    </div>
                </div>

                <!-- 配置表单 -->
                <div class="kina-bili-section-card">
                    <h2>⚙️ 基础配置</h2>
                    <form method="post" class="kina-bili-form">
                        <?php wp_nonce_field('kina_bili_save'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="kina_bili_uid">B站UID</label></th>
                                <td>
                                    <div class="kina-bili-input-wrap">
                                        <span class="kina-bili-input-prefix">space.bilibili.com/</span>
                                        <input type="text" id="kina_bili_uid" name="uid" value="<?php echo $uid; ?>" placeholder="输入纯数字UID" class="regular-text">
                                    </div>
                                    <p class="description">在个人空间地址栏中查看，例如 space.bilibili.com/208259</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="kina_bili_save" class="button button-primary">保存设置</button>
                            <button type="button" id="kina-bili-test-btn" class="button" <?php echo empty($uid) ? 'disabled' : ''; ?>>测试连接</button>
                        </p>
                    </form>

                    <div id="kina-bili-test-result" class="kina-bili-test-result" style="display:none;">
                        <div class="kina-bili-test-loading">正在测试连接...</div>
                        <div class="kina-bili-test-success" style="display:none;">
                            <h4>✅ 连接成功</h4>
                            <div class="kina-bili-test-info">
                                <img id="test-face" src="" alt="" referrerpolicy="no-referrer">
                                <div>
                                    <p><strong>用户名：</strong><span id="test-name"></span></p>
                                    <p><strong>等级：</strong><span id="test-level"></span></p>
                                    <p><strong>粉丝：</strong><span id="test-fans"></span></p>
                                    <p><strong>直播：</strong><span id="test-live"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="kina-bili-test-error" style="display:none;">
                            <h4>❌ 连接失败</h4>
                            <p id="test-error-msg"></p>
                            <div class="kina-bili-privacy-tip">
                                <strong>💡 隐私设置提醒：</strong><br>
                                如果数据为空，请检查B站隐私设置：<br>
                                1. 进入B站个人空间 → 设置 → 隐私设置<br>
                                2. 确保"公开我的关注列表"、"公开我的粉丝列表"、"公开我的投稿视频"等选项已开启<br>
                                3. 直播状态需要开启"公开我的直播间信息"
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 缓存说明 -->
                <div class="kina-bili-section-card">
                    <h2>🔄 数据缓存策略</h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>数据类型</th>
                                <th>缓存时间</th>
                                <th>更新方式</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>用户信息</td>
                                <td>10分钟</td>
                                <td>页面加载时自动更新</td>
                            </tr>
                            <tr>
                                <td>直播状态</td>
                                <td>2分钟</td>
                                <td>每5分钟后台刷新 + 前台30秒轮询</td>
                            </tr>
                            <tr>
                                <td>视频列表</td>
                                <td>30分钟</td>
                                <td>页面加载时自动更新</td>
                            </tr>
                            <tr>
                                <td>追番列表</td>
                                <td>1小时</td>
                                <td>页面加载时自动更新</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    // 卸载时清理
    public static function deactivate() {
        wp_clear_scheduled_hook('kina_bili_refresh_live_cron');
        delete_option('kina_bilibili_settings');
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kina_bili_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_kina_bili_%'");
    }
}

// 初始化
add_action('plugins_loaded', array('Kina_Bilibili_Integration', 'get_instance'));

// 卸载钩子
register_deactivation_hook(__FILE__, array('Kina_Bilibili_Integration', 'deactivate'));
