<?php
define('IM_STANDALONE', true);
require_once dirname(dirname(__FILE__)) . '/security.php';
require_once __DIR__ . '/init.php';

/* 全站统一账号：未登录一律回主站登录页 */
if (!isLoggedIn('user_id')) {
    header('Location: ../login.php?next=' . rawurlencode('im/chat.php'));
    exit;
}

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $_SESSION['im_token'] = $_GET['token'];
}

if (isset($_GET['lang']) && in_array($_GET['lang'], ['zh', 'en'], true)) {
    setLang($_GET['lang']);
    header('Location: chat.php');
    exit;
}

/* 顶部导航数据与 chrome：与全站共用 inc/header.php（IM 不再自建导航/登录屏） */
$BASE = '../';
$pageTitle = L('nav_im');
$active = 'im';
$hideFooter = true;
$extraCss = array('im/chat.css');
$extraHeadJS = <<<'JSEOT'
(function () {
    try {
        var cur = window.location.search;
        if (cur.indexOf('lang=') === -1) {
            var savedLan = localStorage.getItem('im-lang');
            if (savedLan === 'zh' || savedLan === 'en') {
                localStorage.setItem('im-lang', savedLan);
                window.location.replace('?lang=' + savedLan + (cur ? '&' + cur.slice(1) : ''));
            }
        }
        document.addEventListener('click', function (e) {
            var t = e.target.closest('[data-set-lang]');
            if (t) { try { localStorage.setItem('im-lang', t.getAttribute('data-set-lang')); } catch (er) {} }
        });
    } catch (e) {}
})();
JSEOT;
require_once dirname(dirname(__FILE__)) . '/inc/header.php';
?>

<!-- ========== 主聊天界面 ========== -->
<div class="chat-full chat-hidden im-app" id="chatApp">
    <!-- 侧边栏 -->
    <aside class="chat-sidebar im-sidebar">
        <div class="chat-sidebar-header im-sidebar-head">
            <span class="chat-user-avatar im-user-avatar" id="chatUserAvatar" title="<?php echo L('my_profile'); ?>"></span>
            <div class="chat-user-info im-user-info">
                <div class="chat-user-name im-user-name" id="chatUserName">--</div>
                <div class="chat-user-role im-user-role" id="chatUserRole"><?php echo L('role_user'); ?></div>
                <div class="chat-user-bio im-user-bio" id="chatUserBio"></div>
            </div>
            <div class="chat-sidebar-actions im-sidebar-actions" role="toolbar" aria-label="<?php echo L('admin_backend'); ?>">
                <button class="icon-btn im-icon-btn" id="chatDevicesBtn" title="<?php echo L('device_mgmt'); ?>" aria-label="<?php echo L('device_mgmt'); ?>"><i class="fas fa-laptop"></i></button>
                <button class="icon-btn im-icon-btn" id="chatAdminBtn" title="<?php echo L('admin_backend'); ?>" style="display:none;" aria-label="<?php echo L('admin_backend'); ?>"><i class="fas fa-cog"></i></button>
                <span class="sep" aria-hidden="true"></span>
                <button class="icon-btn im-icon-btn is-danger" id="chatLogoutBtn" title="<?php echo L('logout'); ?>" aria-label="<?php echo L('logout'); ?>"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </div>

        <div class="chat-sidebar-search im-sidebar-search">
            <i class="fas fa-search im-search-ico"></i>
            <input type="text" id="chatSearch" placeholder="<?php echo L('chat_search_ph'); ?>" autocomplete="off">
            <button type="button" id="chatSearchClear" class="im-search-clear chat-hidden"><i class="fas fa-times-circle"></i></button>
        </div>

        <div class="chat-cat-tabs im-cat-tabs">
            <button class="tab-btn im-tab-btn active" data-cat="chats"><i class="fas fa-comment"></i> <?php echo L('cat_chats'); ?></button>
            <button class="tab-btn im-tab-btn" data-cat="groups"><i class="fas fa-users"></i> <?php echo L('cat_groups'); ?></button>
            <button class="tab-btn im-tab-btn" data-cat="channels"><i class="fas fa-bullhorn"></i> <?php echo L('cat_channels'); ?></button>
        </div>

        <div class="chat-cat-actions im-cat-actions" id="chatCatActions">
            <button class="mini-btn im-mini-btn" id="createGroupBtn"><i class="fas fa-plus"></i> <?php echo L('create_group'); ?></button>
            <button class="mini-btn im-mini-btn" id="createChannelBtn"><i class="fas fa-plus"></i> <?php echo L('create_channel'); ?></button>
            <button class="mini-btn im-mini-btn" id="addContactBtn"><i class="fas fa-user-plus"></i> <?php echo L('add_contact'); ?></button>
            <button class="mini-btn im-mini-btn" id="openStickersBtn"><i class="fas fa-sticky-note"></i> <?php echo L('open_stickers'); ?></button>
        </div>

        <div class="chat-list im-chat-list" id="chatListContainer"></div>
    </aside>
    <div class="chat-resizer" id="chatResizer" title="<?php echo L('resize_hint'); ?>" role="separator" aria-orientation="vertical"></div>

    <!-- 主聊天区 -->
    <main class="chat-main im-main" id="chatMain">
        <div class="chat-main-header im-main-header" id="chatMainHeader">
            <div class="im-main-header-top">
                <button id="chatBackBtn" class="icon-btn im-icon-btn" style="display:none;margin-right:8px;" aria-label="<?php echo L('back'); ?>">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-main-title-wrap im-main-title-wrap">
                    <span class="chat-main-title im-main-title" id="chatMainTitle"><?php echo L('select_chat'); ?></span>
                    <div class="chat-main-subtitle im-main-subtitle" id="chatMainSubtitle"></div>
                </div>
                <span class="chat-clock im-clock" id="appClock" title="<?php echo L('now_time'); ?>">--:--:--</span>
            </div>
            <div class="chat-main-actions-row im-main-actions-row">
                <div class="im-main-actions-compact">
                    <button class="mini-btn im-mini-btn" id="chatSearchBtn" title="<?php echo L('search_current_chat'); ?>" style="display:none;"><i class="fas fa-search"></i></button>
                    <button class="mini-btn im-mini-btn" id="chatFilterBtn" title="<?php echo L('filter_messages'); ?>" style="display:none;"><i class="fas fa-filter"></i></button>
                    <button class="mini-btn im-mini-btn" id="chatSelectBtn" title="<?php echo L('multi_select'); ?>" style="display:none;"><i class="fas fa-check-double"></i></button>
                </div>
                <div class="chat-main-actions im-main-actions" id="chatGroupActions" style="display:none;">
                    <button class="mini-btn im-mini-btn" id="groupInfoBtn"><i class="fas fa-info-circle"></i> <?php echo L('group_info'); ?></button>
                    <button class="mini-btn im-mini-btn" id="inviteBtn"><i class="fas fa-user-plus"></i> <?php echo L('invite'); ?></button>
                </div>
                <div class="chat-main-actions im-main-actions" id="chatChannelActions" style="display:none;">
                    <button class="mini-btn im-mini-btn" id="subscribeBtn"><i class="fas fa-bell"></i> <?php echo L('subscribe_publish'); ?></button>
                    <button class="mini-btn im-mini-btn" id="channelInfoBtn"><i class="fas fa-info-circle"></i> <?php echo L('channel_info'); ?></button>
                </div>
                <div class="chat-main-actions im-main-actions" id="chatContactActions" style="display:none;">
                    <button class="mini-btn im-mini-btn" id="secretChatBtn"><i class="fas fa-lock"></i> <?php echo L('secret_chat'); ?></button>
                    <button class="mini-btn im-mini-btn" id="contactInfoBtn"><i class="fas fa-info-circle"></i> <?php echo L('contact_info'); ?></button>
                    <button class="mini-btn im-mini-btn" id="exportContactBtn"><i class="fas fa-download"></i> <?php echo L('export'); ?></button>
                    <button class="mini-btn im-mini-btn" id="importContactBtn"><i class="fas fa-upload"></i> <?php echo L('import'); ?></button>
                </div>
            </div>
        </div>

        <!-- 聊天内搜索 -->
        <div class="chat-inline-search chat-hidden im-inline-search" id="chatInlineSearch">
            <input type="text" id="chatSearchInput" placeholder="<?php echo L('search_chat_ph'); ?>" style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;">
            <span id="chatSearchCount" style="font-size:11px;color:var(--text-subtle);white-space:nowrap;"></span>
            <button class="mini-btn im-mini-btn" id="chatSearchPrev"><i class="fas fa-chevron-up"></i></button>
            <button class="mini-btn im-mini-btn" id="chatSearchNext"><i class="fas fa-chevron-down"></i></button>
            <button class="mini-btn im-mini-btn" id="chatSearchClose"><i class="fas fa-times"></i></button>
        </div>

        <!-- 多选操作栏 -->
        <div class="chat-select-bar chat-hidden im-select-bar" id="chatSelectBar">
            <span id="chatSelectCount" style="font-size:13px;font-weight:600;"></span>
            <div style="display:flex;gap:4px;">
                <button class="mini-btn im-mini-btn" id="chatSelectForward"><i class="fas fa-forward"></i> <?php echo L('forward'); ?></button>
                <button class="mini-btn im-mini-btn danger" id="chatSelectDelete" style="color:var(--error);"><i class="fas fa-trash"></i> <?php echo L('delete'); ?></button>
                <button class="mini-btn im-mini-btn" id="chatSelectCancel"><?php echo L('cancel'); ?></button>
            </div>
        </div>

        <!-- 消息筛选栏 -->
        <div class="chat-filter-bar chat-hidden im-filter-bar" id="chatFilterBar">
            <button class="mini-btn im-mini-btn" data-filter="all"><?php echo L('filter_all'); ?></button>
            <button class="mini-btn im-mini-btn" data-filter="unread"><i class="fas fa-circle"></i> <?php echo L('filter_unread'); ?></button>
            <button class="mini-btn im-mini-btn" data-filter="file"><i class="fas fa-file"></i> <?php echo L('filter_file'); ?></button>
            <button class="mini-btn im-mini-btn" data-filter="img"><i class="fas fa-image"></i> <?php echo L('filter_img'); ?></button>
        </div>

        <div class="chat-messages im-messages" id="chatMessages">
            <div class="chat-msg chat-msg-system"><div class="chat-msg-bubble"><?php echo L('select_chat_hint'); ?></div></div>
        </div>
        <div class="chat-typing im-typing" id="chatTyping"></div>

        <!-- 输入区域 -->
        <div class="chat-input-area">
            <!-- 命令菜单 -->
            <div class="chat-cmd-menu chat-hidden im-cmd-menu" id="chatCmdMenu">
                <div class="chat-cmd-item im-cmd-item" data-cmd="emoji"><?php echo L('cmd_insert_emoji'); ?></div>
                <div class="chat-cmd-item im-cmd-item" data-cmd="sticker"><?php echo L('cmd_insert_sticker'); ?></div>
                <div class="chat-cmd-item im-cmd-item" data-cmd="quote"><?php echo L('cmd_reply_msg'); ?></div>
                <div class="chat-cmd-item im-cmd-item" data-cmd="clear"><?php echo L('cmd_clear_chat'); ?></div>
            </div>
            <div class="chat-input-tools im-input-tools" style="display:flex;gap:4px;padding-bottom:6px;flex-wrap:wrap;">
                <button class="icon-btn im-icon-btn" id="chatEmojiBtn" title="Emoji"><i class="fas fa-smile"></i></button>
                <button class="icon-btn im-icon-btn" id="chatMediaBtn" title="<?php echo L('send_file'); ?>"><i class="fas fa-paperclip"></i></button>
                <button class="icon-btn im-icon-btn" id="chatStickerBtn" title="<?php echo L('sticker'); ?>"><i class="fas fa-sticky-note"></i></button>
                <button class="icon-btn im-icon-btn" id="chatVoiceBtn" title="<?php echo L('voice_msg'); ?>"><i class="fas fa-microphone"></i></button>
                <button class="icon-btn im-icon-btn" id="chatSelfDestructBtn" title="<?php echo L('self_destruct'); ?>" style="position:relative;">
                    <i class="fas fa-bomb"></i>
                    <span id="chatSelfDestructBadge" style="display:none;position:absolute;top:-4px;right:-4px;background:var(--error);color:#fff;font-size:9px;width:16px;height:16px;border-radius:50%;align-items:center;justify-content:center;">5</span>
                </button>
                <button class="icon-btn im-icon-btn" id="chatGifBtn" title="<?php echo L('gif'); ?>"><i class="fas fa-icons"></i></button>
                <input type="file" id="chatMediaInput" style="display:none;" multiple accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx">
                <input type="file" id="chatBgInput" style="display:none;" accept="image/*">
                <div style="flex:1;"></div>
                <button class="icon-btn im-icon-btn" id="chatFavoritesBtn" title="<?php echo L('fav_msg'); ?>" style="display:none;"><i class="fas fa-star"></i></button>
                <span class="chat-self-destruct-hint" id="chatSelfDestructHint" style="font-size:11px;color:var(--text-subtle);display:none;"><?php echo L('self_destruct_5s'); ?></span>
            </div>
            <div class="chat-input-quote im-input-quote" id="chatInputQuote" style="display:none;">
                <span id="chatInputQuoteText"></span>
                <button class="chat-input-quote-close im-input-quote-close" id="chatInputQuoteClose">&times;</button>
            </div>
            <div class="chat-input-row">
                <textarea id="chatInput" rows="1" placeholder="<?php echo L('type_message'); ?>"></textarea>
                <button class="tool-btn primary chat-send-btn im-btn im-btn-primary" id="chatSendBtn" disabled><i class="fas fa-paper-plane"></i></button>
            </div>
            <span id="chatChannelReadonlyTip" style="display:none;font-size:12px;color:var(--text-muted);margin-left:4px;"></span>
        </div>

    </main>

    <!-- 信息面板 -->
    <aside class="chat-info-panel chat-hidden-mobile im-info-panel" id="infoPanel">
        <div class="chat-info-header im-info-header">
            <span><?php echo L('details'); ?></span>
            <button class="icon-btn im-icon-btn" id="infoCloseBtn"><i class="fas fa-times"></i></button>
        </div>
        <div class="chat-info-content im-info-body" id="infoContent"></div>
    </aside>
</div>

<!-- ========== 管理后台 ========== -->
<div class="chat-admin chat-hidden im-admin" id="adminPanel">
    <aside class="chat-admin-sidebar im-admin-sidebar">
        <h2><i class="fas fa-cog"></i> <?php echo L('admin_title'); ?> <span id="adminRoleLabel" style="font-size:11px;color:var(--text-subtle);font-weight:400;"></span></h2>
        <nav class="chat-admin-nav im-admin-nav">
            <div class="chat-admin-nav-item im-admin-nav-item active" data-section="overview"><i class="fas fa-chart-pie"></i> <?php echo L('admin_overview'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="users"><i class="fas fa-users"></i> <?php echo L('admin_users'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="groups"><i class="fas fa-users-cog"></i> <?php echo L('admin_groups'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="channels"><i class="fas fa-bullhorn"></i> <?php echo L('admin_channels'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="messages"><i class="fas fa-envelope"></i> <?php echo L('admin_msg_audit'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="stats"><i class="fas fa-chart-line"></i> <?php echo L('admin_stats'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="settings"><i class="fas fa-wrench"></i> <?php echo L('admin_system_settings'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="logs"><i class="fas fa-history"></i> <?php echo L('admin_logs'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="backup"><i class="fas fa-database"></i> <?php echo L('admin_backup'); ?></div>
            <div class="chat-admin-nav-item im-admin-nav-item" data-section="notifications"><i class="fas fa-bell"></i> <?php echo L('admin_notifications'); ?></div>
        </nav>
        <div style="padding:12px 16px;border-top:1px solid var(--border);">
            <button class="chat-admin-back im-btn im-btn-ghost" id="adminBackBtn"><i class="fas fa-arrow-left"></i> <?php echo L('back_chat'); ?></button>
        </div>
    </aside>
    <main class="chat-admin-main im-admin-main">
        <div class="chat-admin-section im-admin-section active" id="adminSectionOverview">
            <h2><?php echo L('admin_overview'); ?></h2>
            <div class="chat-admin-stats">
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatUsers">0</div><div class="chat-admin-stat-label"><?php echo L('st_users'); ?></div></div>
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatGroups">0</div><div class="chat-admin-stat-label"><?php echo L('st_groups'); ?></div></div>
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatChannels">0</div><div class="chat-admin-stat-label"><?php echo L('st_channels'); ?></div></div>
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatMessages">0</div><div class="chat-admin-stat-label"><?php echo L('st_msgs'); ?></div></div>
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatDAU">0</div><div class="chat-admin-stat-label"><?php echo L('st_dau'); ?></div></div>
            </div>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionUsers">
            <h2><?php echo L('admin_users'); ?></h2>
            <input type="text" class="chat-admin-search im-admin-search" id="adminUserSearch" placeholder="<?php echo L('search_users_ph'); ?>">
            <div id="adminUserList"></div>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionGroups">
            <h2><?php echo L('admin_groups'); ?></h2>
            <input type="text" class="chat-admin-search im-admin-search" id="adminGroupSearch" placeholder="<?php echo L('search_groups_ph'); ?>">
            <div id="adminGroupList"></div>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionChannels">
            <h2><?php echo L('admin_channels'); ?></h2>
            <input type="text" class="chat-admin-search im-admin-search" id="adminChannelSearch" placeholder="<?php echo L('search_channels_ph'); ?>">
            <div id="adminChannelList"></div>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionMessages">
            <h2><?php echo L('admin_msg_audit'); ?></h2>
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                <input type="text" class="chat-admin-search im-admin-search" id="adminMsgSearch" placeholder="<?php echo L('search_msgs_ph'); ?>">
                <button class="mini-btn im-mini-btn" id="adminMsgRefresh"><i class="fas fa-sync-alt"></i> <?php echo L('refresh'); ?></button>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;align-items:center;">
                <select id="adminMsgFilterType" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;">
                    <option value=""><?php echo L('all_types'); ?></option>
                    <option value="text"><?php echo L('type_text'); ?></option>
                    <option value="image"><?php echo L('type_image'); ?></option>
                    <option value="voice"><?php echo L('type_voice'); ?></option>
                    <option value="file"><?php echo L('type_file'); ?></option>
                    <option value="sticker"><?php echo L('type_sticker'); ?></option>
                </select>
                <select id="adminMsgFilterStatus" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;">
                    <option value=""><?php echo L('all_status'); ?></option>
                    <option value="normal"><?php echo L('status_normal'); ?></option>
                    <option value="recalled"><?php echo L('status_recalled'); ?></option>
                    <option value="expired"><?php echo L('status_expired'); ?></option>
                </select>
                <input type="date" id="adminMsgFilterFrom" title="<?php echo L('start_date'); ?>" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;">
                <span style="font-size:12px;color:var(--text-subtle);"><?php echo L('date_to'); ?></span>
                <input type="date" id="adminMsgFilterTo" title="<?php echo L('end_date'); ?>" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;">
                <button class="mini-btn im-mini-btn" id="adminMsgFilterReset"><?php echo L('reset'); ?></button>
            </div>
            <div id="adminMsgList"></div>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionStats">
            <h2><?php echo L('admin_stats'); ?></h2>
            <div class="chat-admin-stats">
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatSentToday">0</div><div class="chat-admin-stat-label"><?php echo L('st_sent_today'); ?></div></div>
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatRegToday">0</div><div class="chat-admin-stat-label"><?php echo L('st_reg_today'); ?></div></div>
                <div class="chat-admin-stat"><div class="chat-admin-stat-num" id="adminStatActiveUsers">0</div><div class="chat-admin-stat-label"><?php echo L('st_online'); ?></div></div>
            </div>
            <canvas id="trafficChart" width="600" height="200" style="width:100%;max-width:600px;height:200px;border:1px solid var(--border);border-radius:8px;margin-top:12px;"></canvas>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionSettings">
            <h2><?php echo L('admin_system_settings'); ?></h2>
            <div style="background:var(--bg-soft);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:12px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;"><?php echo L('data_capacity'); ?></div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
                    <div><label style="font-size:13px;display:block;margin-bottom:4px;"><?php echo L('setting_retention'); ?></label><input type="number" id="adminSettingRetention" min="0" value="0" style="padding:8px;border:1px solid var(--border);border-radius:8px;"></div>
                    <div><label style="font-size:13px;display:block;margin-bottom:4px;"><?php echo L('setting_max_group'); ?></label><input type="number" id="adminSettingMaxGroup" min="10" value="200" style="padding:8px;border:1px solid var(--border);border-radius:8px;"></div>
                    <div><label style="font-size:13px;display:block;margin-bottom:4px;"><?php echo L('setting_self_destruct'); ?></label><input type="number" id="adminSettingSelfDestruct" min="0" value="5" style="padding:8px;border:1px solid var(--border);border-radius:8px;"></div>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;margin-top:14px;"><input type="checkbox" id="adminSettingAutoBackup" checked> <?php echo L('setting_auto_backup'); ?></label>
                </div>
            </div>
            <div style="background:var(--bg-soft);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:12px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;"><?php echo L('security_login'); ?></div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingAllowRegister" checked> <?php echo L('setting_allow_register'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingAllowKey" checked> <?php echo L('setting_allow_key'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingAllowDevice" checked> <?php echo L('setting_allow_device'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingAllowRegisterAuto" checked> <?php echo L('setting_auto_register'); ?></label>
                </div>
            </div>
            <div style="background:var(--bg-soft);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:12px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;"><?php echo L('feature_modules'); ?></div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingVoice" checked> <?php echo L('setting_voice'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingSticker" checked> <?php echo L('setting_sticker'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingGif" checked> <?php echo L('setting_gif'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingSecret" checked> <?php echo L('setting_secret'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingOnline" checked> <?php echo L('setting_online'); ?></label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="adminSettingMessages" checked> <?php echo L('setting_messages'); ?></label>
                </div>
            </div>
            <button class="mini-btn im-mini-btn" id="adminSaveSettings" style="padding:8px 20px;"><?php echo L('save_settings'); ?></button>
            <button class="mini-btn im-mini-btn" id="adminSettingsReset" style="padding:8px 20px;margin-left:8px;"><?php echo L('reset_defaults'); ?></button>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionLogs">
            <h2><?php echo L('admin_logs'); ?></h2>
            <div id="adminLogList"><p style="font-size:13px;color:var(--text-subtle);"><?php echo L('no_logs'); ?></p></div>
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionBackup">
            <h2><?php echo L('admin_backup'); ?></h2>
            <p style="font-size:13px;color:var(--text-subtle);margin-bottom:12px;"><?php echo L('backup_desc'); ?></p>
            <button class="mini-btn im-mini-btn" id="adminBackupBtn" style="margin-right:8px;"><i class="fas fa-download"></i> <?php echo L('export_backup'); ?></button>
            <button class="mini-btn im-mini-btn" id="adminRestoreBtn"><i class="fas fa-upload"></i> <?php echo L('import_restore'); ?></button>
            <input type="file" id="adminRestoreInput" accept=".json" style="display:none;">
        </div>
        <div class="chat-admin-section im-admin-section" id="adminSectionNotifications">
            <h2><?php echo L('admin_notifications'); ?><i class="fas fa-bell" style="margin-left:6px;color:var(--primary);"></i></h2>
            <div class="chat-admin-card">
                <label><?php echo L('broadcast_to_all'); ?></label>
                <input type="text" id="adminBroadcastTitle" class="chat-admin-search" placeholder="<?php echo L('title_optional_ph'); ?>" style="width:100%;">
                <textarea id="adminBroadcastText" placeholder="<?php echo L('broadcast_ph'); ?>" rows="3" style="width:100%;margin-top:6px;"></textarea>
                <div style="margin-top:8px;">
                    <button class="chat-modal-confirm mini-btn im-btn im-btn-primary" id="adminBroadcastBtn"><?php echo L('publish_broadcast'); ?></button>
                </div>
            </div>
            <div style="margin-top:16px;font-size:13px;color:var(--text-muted);"><?php echo L('report_records'); ?></div>
            <div id="adminNotificationList"><p style="font-size:13px;color:var(--text-subtle);"><?php echo L('no_notifications'); ?></p></div>
            <div style="margin-top:16px;font-size:13px;color:var(--text-muted);"><?php echo L('broadcast_history'); ?></div>
            <div id="adminBroadcastHistory"><p style="font-size:13px;color:var(--text-subtle);"><?php echo L('no_broadcasts'); ?></p></div>
        </div>
    </main>
</div>

<!-- ========== 常驻面板（Toast / 表情 / 贴纸 / 回应） ========== -->
<div class="chat-toast" id="chatToast"></div>

<div class="chat-emoji-picker chat-hidden" id="emojiPicker">
    <div class="chat-emoji-picker-header">
        <span><?php echo L('emoji_picker'); ?></span>
        <button type="button" id="emojiPickerClose" style="background:none;border:none;font-size:14px;cursor:pointer;color:var(--text-subtle);line-height:1;">✕</button>
    </div>
    <div id="emojiCategoryTabs"></div>
    <div class="chat-emoji-picker-grid" id="emojiPickerGrid"></div>
</div>

<div class="chat-sticker-picker chat-hidden" id="stickerPicker">
    <div class="chat-sticker-picker-header">
        <span><?php echo L('sticker_pack'); ?></span>
        <button type="button" id="stickerPickerClose" style="background:none;border:none;font-size:14px;cursor:pointer;color:var(--text-subtle);line-height:1;">✕</button>
    </div>
    <div class="chat-sticker-picker-grid" id="stickerPickerGrid"></div>
</div>

<div class="chat-reaction-picker chat-hidden" id="reactionPicker">
    <div id="reactionGrid"></div>
</div>

<!-- Mobile responsive -->
<style>
    .chat-hidden-mobile { display: flex; }
    .chat-info-panel { display: none; }
    .chat-info-panel.show,
    .chat-info-panel.show-mobile { display: flex !important; }
    @media (max-width: 768px) {
        .chat-hidden-mobile { display: none; }
        .show-mobile { display: flex !important; }
        .chat-main { display: none; }
        .chat-main.show-mobile { display: flex; position: fixed; inset: var(--im-nav-h) 0 0 0; z-index: 10; background: var(--bg-base); flex-direction: column; }
        .chat-info-panel.show-mobile { display: flex; position: fixed; inset: var(--im-nav-h) 0 0 0; z-index: 20; background: var(--bg-base); width: 100%; flex-direction: column; }
        #chatBackBtn { display: flex !important; }
    }
</style>

<script>
    window.AQUA_CHAT_API = '/api';
    window.AQUA_CHAT_TOKEN = <?php echo $imBridgeToken !== '' ? json_encode($imBridgeToken) : 'null'; ?>;
    window.AQUA_CHAT_UID   = <?php echo json_encode((string)$imBridgeUid); ?>;
    window.AQUA_CHAT_USERNAME = <?php echo json_encode($imBridgeUname); ?>;
    window.AQUA_CHAT_NICK    = <?php echo json_encode($imBridgeNick); ?>;
    window.AQUA_I18N = <?php echo json_encode(L_All(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="js/chat.js"></script>
<script>
/* 侧栏拖拽调宽 */
(function () {
    var app = document.getElementById('chatApp');
    var resizer = document.getElementById('chatResizer');
    if (!app || !resizer) return;
    var MIN = 200, MAX = 520;
    var saved = 0;
    try { saved = parseInt(localStorage.getItem('im-sidebar-w'), 10); } catch (e) {}
    if (saved >= MIN && saved <= MAX) app.style.setProperty('--sidebar-width', saved + 'px');
    var dragging = false;
    function start(e) {
        if (window.innerWidth <= 768) return;
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        dragging = true;
        resizer.classList.add('active');
        app.classList.add('im-resizing');
    }
    function move(e) {
        if (!dragging) return;
        var rect = app.getBoundingClientRect();
        var x = e.clientX - rect.left;
        x = Math.max(MIN, Math.min(MAX, x));
        app.style.setProperty('--sidebar-width', x + 'px');
    }
    function end() {
        if (!dragging) return;
        dragging = false;
        resizer.classList.remove('active');
        app.classList.remove('im-resizing');
        var w = parseFloat(app.style.getPropertyValue('--sidebar-width'));
        if (w >= MIN && w <= MAX) {
            try { localStorage.setItem('im-sidebar-w', String(Math.round(w))); } catch (e) {}
        }
    }
    resizer.addEventListener('mousedown', start);
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', end);
    resizer.addEventListener('touchstart', function (e) {
        for (var i = 0; i < e.changedTouches.length; i++) start(e.changedTouches[i]);
    }, { passive: false });
    document.addEventListener('touchmove', function (e) {
        for (var i = 0; i < e.changedTouches.length; i++) move(e.changedTouches[i]);
    }, { passive: false });
    document.addEventListener('touchend', end);
})();
</script>
<?php require_once dirname(dirname(__FILE__)) . '/inc/footer.php'; ?>
