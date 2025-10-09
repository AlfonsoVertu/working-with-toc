;(function () {
    'use strict';

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return value.replace(/([\s#:?&.,])/g, '\\$1');
    }

    var initialized = false;

    function applyContainerColors(container) {
        var map = {
            bg: '--wwt-toc-bg',
            text: '--wwt-toc-text',
            link: '--wwt-toc-link',
            titleBg: '--wwt-toc-title-bg',
            titleColor: '--wwt-toc-title-color'
        };

        Object.keys(map).forEach(function (dataKey) {
            var cssVar = map[dataKey];
            var value = container.dataset[dataKey];
            if (value) {
                container.style.setProperty(cssVar, value);
            }
        });
    }

    function togglePanel(container, expanded) {
        var button = container.querySelector('.wwt-toc-toggle');
        var panel = container.querySelector('.wwt-toc-content');

        container.dataset.expanded = String(expanded);
        if (button) {
            button.setAttribute('aria-expanded', String(expanded));
        }
        if (panel) {
            if (expanded) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', '');
            }
        }
    }

    function createHighlightBinder() {
        if (!('IntersectionObserver' in window)) {
            return function () {};
        }

        var bound = false;

        return function bindHighlight() {
            if (bound) {
                return;
            }
            bound = true;

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var id = entry.target.getAttribute('id');
                    if (!id) {
                        return;
                    }

                    var link = document.querySelector('.wwt-toc-list a[href="#' + cssEscape(id) + '"]');
                    if (!link) {
                        return;
                    }

                    if (entry.isIntersecting) {
                        var activeLinks = document.querySelectorAll('.wwt-toc-list a.is-active');
                        Array.prototype.forEach.call(activeLinks, function (active) {
                            active.classList.remove('is-active');
                        });
                        link.classList.add('is-active');
                    }
                });
            }, {
                rootMargin: '-50% 0px -40% 0px',
                threshold: 0.1
            });

            var targets = document.querySelectorAll('h2[id], h3[id], h4[id], h5[id], h6[id]');
            Array.prototype.forEach.call(targets, function (target) {
                observer.observe(target);
            });
        };
    }

    function init() {
        if (initialized) {
            return;
        }

        var containers = document.querySelectorAll('[data-wwt-toc]');
        if (!containers.length) {
            return;
        }

        initialized = true;

        var bindHighlight = createHighlightBinder();

        Array.prototype.forEach.call(containers, function (container) {
            applyContainerColors(container);

            var button = container.querySelector('.wwt-toc-toggle');
            var panel = container.querySelector('.wwt-toc-content');

            if (!button || !panel) {
                return;
            }

            var expanded = container.dataset.expanded === 'true';
            togglePanel(container, expanded);

            var ensureHighlight = function () {
                if (container.dataset.expanded === 'true') {
                    if ('requestIdleCallback' in window) {
                        window.requestIdleCallback(bindHighlight, { timeout: 1000 });
                    } else {
                        setTimeout(bindHighlight, 0);
                    }
                }
            };

            if (expanded) {
                ensureHighlight();
            }

            button.addEventListener('click', function () {
                var isExpanded = container.dataset.expanded === 'true';
                togglePanel(container, !isExpanded);
                ensureHighlight();
            });

            button.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    togglePanel(container, false);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
