(function () {
    'use strict';

    const cssEscape = (value) => {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return value.replace(/([\s#:?&.,])/g, '\$1');
    };

    const containers = document.querySelectorAll('[data-wwt-toc]');
    if (!containers.length) {
        return;
    }

    const togglePanel = (container, expanded) => {
        const button = container.querySelector('.wwt-toc-toggle');
        const panel = container.querySelector('.wwt-toc-content');

        container.dataset.expanded = String(expanded);
        button.setAttribute('aria-expanded', String(expanded));
        if (expanded) {
            panel.removeAttribute('hidden');
        } else {
            panel.setAttribute('hidden', '');
        }
    };

    containers.forEach((container) => {
        const button = container.querySelector('.wwt-toc-toggle');
        const panel = container.querySelector('.wwt-toc-content');
        const links = panel ? Array.from(panel.querySelectorAll('a[href^="#"]')) : [];

        if (!button || !panel) {
            return;
        }

        button.addEventListener('click', () => {
            const expanded = container.dataset.expanded === 'true';
            togglePanel(container, !expanded);
        });

        button.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                togglePanel(container, false);
            }
        });

        if (links.length) {
            links.forEach((link) => {
                link.addEventListener('click', () => togglePanel(container, false));
            });
        }
    });

    const highlightActiveHeading = () => {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const id = entry.target.getAttribute('id');
                if (!id) {
                    return;
                }

                const link = document.querySelector('.wwt-toc-list a[href="#' + cssEscape(id) + '"]');
                if (!link) {
                    return;
                }

                if (entry.isIntersecting) {
                    document.querySelectorAll('.wwt-toc-list a.is-active').forEach((active) => {
                        active.classList.remove('is-active');
                    });
                    link.classList.add('is-active');
                }
            });
        }, {
            rootMargin: '-50% 0px -40% 0px',
            threshold: 0.1,
        });

        const targets = document.querySelectorAll('h2[id], h3[id], h4[id], h5[id], h6[id]');
        targets.forEach((target) => observer.observe(target));
    };

    if ('IntersectionObserver' in window) {
        window.addEventListener('load', highlightActiveHeading);
    }
})();
