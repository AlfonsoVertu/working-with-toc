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
    });
})(jQuery);
