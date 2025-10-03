(function ($) {
    'use strict';

    $(function () {
        var cards = $('.wwt-toc-card');
        cards.on('mouseenter', function () {
            $(this).addClass('wwt-hover');
        }).on('mouseleave', function () {
            $(this).removeClass('wwt-hover');
        });

        var labels = window.wwtTocAdmin || { on: 'Activated', off: 'Deactivated' };
        $('.wwt-switch input').on('change', function () {
            var $this = $(this);
            var label = $this.closest('.wwt-toc-card').find('h2').text();
            var state = $this.is(':checked') ? labels.on : labels.off;
            if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
                window.wp.a11y.speak(label + ' ' + state);
            }
        });

        var metaBox = $('.wwt-toc-meta');
        if (metaBox.length) {
            var updateColorValue = function (input) {
                var $input = $(input);
                var target = $input.attr('id');
                if (!target) {
                    return;
                }

                var display = metaBox.find('.wwt-toc-meta__color-value[data-target="' + target + '"]');
                if (display.length) {
                    display.text(($input.val() || '').toUpperCase());
                }
            };

            metaBox.find('.wwt-toc-meta__color-input').each(function () {
                updateColorValue(this);
            }).on('input change', function () {
                updateColorValue(this);
            });

            metaBox.on('click', '.wwt-toc-meta__reset', function (event) {
                event.preventDefault();
                var targetId = $(this).data('target');
                if (!targetId) {
                    return;
                }

                var $field = $('#' + targetId);
                if (!$field.length) {
                    return;
                }

                var defaultValue = $field.data('default');
                if (typeof defaultValue === 'undefined') {
                    return;
                }

                $field.val(defaultValue).trigger('change');
            });
        }
    });
})(jQuery);
