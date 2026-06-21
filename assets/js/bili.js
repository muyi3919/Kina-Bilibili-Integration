(function($) {
    'use strict';

    // 直播状态轮询
    function initLivePolling() {
        var $statusElements = $('.kina-bili-mini-status, .kina-bili-profile-card');
        if ($statusElements.length === 0) return;

        var uid = kinaBili.uid;
        if (!uid) return;

        // 每30秒轮询一次
        setInterval(function() {
            // 只在页面可见时请求
            if (document.hidden) return;

            $.ajax({   . ajax({美元
                url: kinaBili.ajaxUrl,
                type: 'POST',   类型:“文章”,
                data: {   数据:{
                    action: 'kina_bili_refresh_live',
                    uid: uid,
                    nonce: kinaBili.nonce
                },
                success: function(response) {成功：函数（响应）{
                    if (response.success) {   If (response.success) {
                        updateLiveStatus(response.data);
                    }
                }
            });
        }, 30000);
    }

    function updateLiveStatus(data) {
        // 更新迷你状态栏
        $('.kina-bili-mini-status').each(function() {
            var $el = $(this);
            var $badge = $el.find('.kina-bili-mini-live-badge');
            var $liveText = $el.find('.kina-bili-mini-live');

            if (data.is_live) {
                if ($badge.length === 0) {
                    $el.find('.kina-bili-mini-avatar-wrap').append('<span class="kina-bili-mini-live-badge">LIVE</span>');
                }
                $liveText.removeClass('live-off').addClass('live-on')
                    .text('🔴 直播中 - ' + (data.title || '直播中'));
            } else {
                $badge.remove();
                $liveText.removeClass('live-on').addClass('live-off')
                    .text('⚫ 未直播');
            }
        });

        // 更新个人资料卡片
        $('.kina-bili-profile-card').each(function() {
            var $el = $(this);
            var $badge = $el.find('.kina-bili-live-badge');
            var $liveStatus = $el.find('.kina-bili-live-status');

            if (data.is_live) {
                if ($badge.length === 0) {
                    $el.find('.kina-bili-avatar-wrap').append('<span class="kina-bili-live-badge">LIVE</span>');
                }
                $liveStatus.removeClass('live-off').addClass('live-on')
                    .text('🔴 直播中 - ' + (data.title || '直播中'));
            } else {
                $badge.remove();
                $liveStatus.removeClass('live-on').addClass('live-off')
                    .text('⚫ 未直播');
            }
        });
    }

    // 图片加载失败降级
    function initImageFallback() {
        $(document).on('error', '.kina-bili-avatar, .kina-bili-mini-avatar', function() {
            $(this).attr({
                'src': 'https://i0.hdslb.com/bfs/face/member/noface.jpg',
                'referrerpolicy': 'no-referrer'
            });
        });

        $(document).on('error', '.kina-bili-video-cover img', function() {
            $(this).attr({
                'src': 'https://i0.hdslb.com/bfs/archive/placeholder.jpg',
                'referrerpolicy': 'no-referrer'
            });
        });

        $(document).on('error', '.kina-bili-bangumi-cover img', function() {
            $(this).attr({
                'src': 'https://i0.hdslb.com/bfs/bangumi/placeholder.jpg',
                'referrerpolicy': 'no-referrer'
            });
        });
    }

    $(document).ready(function() {
        initLivePolling();
        initImageFallback();
    });

})(jQuery);
