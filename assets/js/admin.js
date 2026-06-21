(function($) {
    'use strict';

    $(document).ready(function() {
        var $testBtn = $('#kina-bili-test-btn');
        var $result = $('#kina-bili-test-result');
        var $uidInput = $('#kina_bili_uid');

        // UID输入时启用/禁用测试按钮
        $uidInput.on('input', function() {
            var val = $(this).val().trim();
            $testBtn.prop('disabled', val === '' || !/^\d+$/.test(val));
        });

        $testBtn.on('click', function() {
            var uid = $uidInput.val().trim();

            if (!uid || !/^\d+$/.test(uid)) {
                alert('请输入有效的纯数字UID');
                return;
            }

            $result.show();
            $result.find('.kina-bili-test-loading').show();
            $result.find('.kina-bili-test-success, .kina-bili-test-error').hide();

            $.ajax({
                url: kinaBiliAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kina_bili_test_api',
                    uid: uid,
                    nonce: kinaBiliAdmin.nonce
                },
                success: function(response) {
                    $result.find('.kina-bili-test-loading').hide();

                    if (response.success) {
                        var data = response.data;
                        $result.find('.kina-bili-test-success').show();
                        $('#test-face').attr('src', data.face || 'https://i0.hdslb.com/bfs/face/member/noface.jpg');
                        $('#test-name').text(data.name || '未知');
                        $('#test-level').text('LV' + (data.level || 0));
                        $('#test-fans').text(data.fans || '0');
                        $('#test-live').text(data.is_live ? ('🔴 直播中 - ' + (data.live_title || '直播中')) : '⚫ 未直播');
                    } else {
                        $result.find('.kina-bili-test-error').show();
                        $('#test-error-msg').text(response.data || '连接失败，请检查UID是否正确');
                    }
                },
                error: function() {
                    $result.find('.kina-bili-test-loading').hide();
                    $result.find('.kina-bili-test-error').show();
                    $('#test-error-msg').text('网络请求失败，请稍后重试');
                }
            });
        });
    });

})(jQuery);
