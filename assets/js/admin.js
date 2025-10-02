(function ($) {
    'use strict';

    $(function () {
        const cards = $('.wwt-toc-card');
        cards.on('mouseenter', function () {
            $(this).addClass('wwt-hover');
        }).on('mouseleave', function () {
            $(this).removeClass('wwt-hover');
        });

        const labels = window.wwtTocAdmin || { on: 'attivato', off: 'disattivato' };
        $('.wwt-switch input').on('change', function () {
            const label = $(this).closest('.wwt-toc-card').find('h2').text();
            const state = $(this).is(':checked') ? labels.on : labels.off;
            wp.a11y.speak(label + ' ' + state);
        });

        const metaBox = $('.wwt-toc-meta');
        if (metaBox.length) {
            const updateColorValue = (input) => {
                const $input = $(input);
                const target = $input.attr('id');
                if (!target) {
                    return;
                }

                const display = metaBox.find('.wwt-toc-meta__color-value[data-target="' + target + '"]');
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
                const targetId = $(this).data('target');
                if (!targetId) {
                    return;
                }

                const $field = $('#' + targetId);
                if (!$field.length) {
                    return;
                }

                const defaultValue = $field.data('default');
                if (typeof defaultValue === 'undefined') {
                    return;
                }

                $field.val(defaultValue).trigger('change');
            });
        }
    });
})(jQuery);
