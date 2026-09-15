document.addEventListener('DOMContentLoaded', function() {
    // W szkoleniach obowiązkowych użytkownik nie powinien móc usuwać kursów
    // z widoku pulpitu. Moodle zapisuje tę operację jako preferencję
    // block_myoverview_hidden_course_<courseid>. Usuwamy akcję z interfejsu,
    // a osobny skrypt wdrożeniowy czyści wcześniej zapisane preferencje.
    var removeCourseHidingControls = function() {
        var selectors = [
            '[data-action="hide-course"]',
            '[data-region="course-action-menu"] [data-action="hide-course"]',
            '[data-filter="grouping"] [data-value="hidden"]',
            '[data-filter="grouping"] option[value="hidden"]',
            '[data-region="filter"] [data-value="hidden"]'
        ];

        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(element) {
                var menuitem = element.closest('li, .dropdown-item');
                if (menuitem && menuitem !== element && menuitem.contains(element)) {
                    menuitem.remove();
                } else {
                    element.remove();
                }
            });
        });

        document.querySelectorAll('.block_myoverview .dropdown-menu a, .block_myoverview .dropdown-menu button').forEach(function(element) {
            var text = (element.textContent || '').trim().toLowerCase();
            if (text === 'usunięte z widoku' || text === 'courses removed from view' || text === 'removed from view') {
                var parent = element.closest('li');
                (parent || element).remove();
            }
        });
    };

    removeCourseHidingControls();

    var observer = new MutationObserver(function() {
        removeCourseHidingControls();
    });
    observer.observe(document.body, {childList: true, subtree: true});

    if (!document.body.classList.contains('pagelayout-login')) {
        // Administrator zachowuje pełny interfejs. Zwykły użytkownik dostaje
        // minimalistyczny widok szkoleniowy skoncentrowany na własnych kursach.
        var isAdmin = Boolean(document.querySelector(
            '[data-key="siteadminnode"], a[href*="/admin/search.php"], a[href*="/admin/index.php"], .primary-navigation a[href*="/admin/"]'
        ));

        if (!isAdmin) {
            document.body.classList.add('fub-student-mode');

            var removeStudentExtras = function() {
                var extraSelectors = [
                    '[data-region="popover-region-messages"]',
                    'a[href*="/message/"]',
                    'a[href*="/calendar/"]',
                    'a[href*="/blog/"]',
                    '.block_calendar_month',
                    '.block_calendar_upcoming',
                    '.block_blog_menu',
                    '.block_blog_recent',
                    '.block_online_users',
                    '.activity.modtype_forum'
                ];

                extraSelectors.forEach(function(selector) {
                    document.querySelectorAll(selector).forEach(function(element) {
                        var item = element.closest('li, .dropdown-item, .nav-item, .block, .activity');
                        (item || element).remove();
                    });
                });
            };

            removeStudentExtras();
            var studentObserver = new MutationObserver(removeStudentExtras);
            studentObserver.observe(document.body, {childList: true, subtree: true});
        }

        return;
    }

    var signup = document.querySelector('.login-signup a');
    if (signup) {
        signup.textContent = 'Zarejestruj się';
        signup.classList.remove('btn-secondary');
        signup.classList.add('btn-primary', 'btn-lg', 'w-100', 'fub-signup-button');
        return;
    }

    if (!document.body.id || document.body.id !== 'page-login-index') {
        return;
    }

    var loginform = document.querySelector('.loginform');
    if (!loginform || document.querySelector('.fub-signup-fallback')) {
        return;
    }

    var wrapper = document.createElement('div');
    wrapper.className = 'login-signup fub-signup-fallback mt-3';

    var link = document.createElement('a');
    link.className = 'btn btn-primary btn-lg w-100 fub-signup-button';
    link.href = (window.M && M.cfg && M.cfg.wwwroot ? M.cfg.wwwroot : '') + '/login/signup.php';
    link.textContent = 'Zarejestruj się';

    wrapper.appendChild(link);
    loginform.appendChild(wrapper);
});
