document.addEventListener('DOMContentLoaded', function() {
    // W szkoleniach obowiązkowych użytkownik nie powinien móc usuwać kursów
    // z widoku pulpitu.
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

    var renderCourseTree = function(node, isPortal) {
        var fragment = document.createDocumentFragment();

        (node.categories || []).forEach(function(category) {
            var details = document.createElement('details');
            details.className = 'fub-course-tree-category';

            var summary = document.createElement('summary');
            summary.textContent = category.name;
            details.appendChild(summary);

            var children = document.createElement('div');
            children.className = 'fub-course-tree-children';
            children.appendChild(renderCourseTree(category, isPortal));
            details.appendChild(children);

            fragment.appendChild(details);
        });

        if (node.courses && node.courses.length) {
            var list = document.createElement('ul');
            list.className = 'fub-course-tree-courses';

            node.courses.forEach(function(course) {
                var item = document.createElement('li');

                var link = document.createElement('a');
                link.href = course.url;
                link.className = 'fub-course-tree-link';
                link.textContent = course.name;
                item.appendChild(link);

                if (isPortal) {
                    var status = document.createElement('span');
                    status.className = course.completed ?
                        'fub-course-status fub-course-status-complete' :
                        'fub-course-status fub-course-status-pending';
                    status.textContent = course.completed ? 'Zapoznano' : 'Do zapoznania';
                    if (course.completed && course.timecompleted) {
                        status.title = 'Potwierdzono: ' + course.timecompleted;
                    }
                    item.appendChild(status);
                }

                list.appendChild(item);
            });

            fragment.appendChild(list);
        }

        return fragment;
    };

    var addTreeSection = function(parent, title, data, isPortal) {
        var section = document.createElement('details');
        section.className = 'fub-course-tree-section';
        section.open = !isPortal;

        var heading = document.createElement('summary');
        heading.className = 'fub-course-tree-section-title';
        heading.textContent = title;
        section.appendChild(heading);

        var body = document.createElement('div');
        body.className = 'fub-course-tree-section-body';

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control fub-course-tree-search';
        search.placeholder = 'Szukaj w ' + title.toLowerCase() + '...';
        search.setAttribute('aria-label', 'Szukaj w ' + title.toLowerCase());
        body.appendChild(search);

        var tree = document.createElement('div');
        tree.className = 'fub-course-tree';
        tree.appendChild(renderCourseTree(data, isPortal));
        body.appendChild(tree);

        search.addEventListener('input', function() {
            var query = search.value.trim().toLowerCase();

            tree.querySelectorAll('li').forEach(function(item) {
                var matches = !query || (item.textContent || '').toLowerCase().includes(query);
                item.hidden = !matches;
            });

            tree.querySelectorAll('details.fub-course-tree-category').forEach(function(details) {
                if (!query) {
                    details.open = false;
                    details.hidden = false;
                    return;
                }
                var visibleItems = details.querySelectorAll('li:not([hidden])').length;
                details.hidden = visibleItems === 0;
                details.open = visibleItems > 0;
            });
        });

        section.appendChild(body);
        parent.appendChild(section);
    };

    var loadMyCoursesTree = function() {
        if (!document.body.classList.contains('pagelayout-mycourses')) {
            return;
        }

        var overview = document.querySelector('.block_myoverview');
        if (!overview || document.querySelector('.fub-course-trees')) {
            return;
        }

        var root = document.createElement('section');
        root.className = 'fub-course-trees';

        var loading = document.createElement('div');
        loading.className = 'alert alert-light';
        loading.textContent = 'Ładowanie kursów...';
        root.appendChild(loading);

        overview.parentNode.insertBefore(root, overview);

        var wwwroot = window.M && M.cfg && M.cfg.wwwroot ? M.cfg.wwwroot : '';
        fetch(wwwroot + '/auth/manualapproval/mycourses_tree.php', {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                root.innerHTML = '';
                addTreeSection(root, 'Portal Wiedzy', data.portal || {}, true);
                addTreeSection(root, 'Szkolenia', data.training || {}, false);
                overview.classList.add('fub-course-overview-hidden');
            })
            .catch(function() {
                root.remove();
                overview.classList.remove('fub-course-overview-hidden');
            });
    };

    if (!document.body.classList.contains('pagelayout-login')) {
        // Nie włączamy trybu studenta na stronach administracyjnych i w
        // pełnoekranowym edytorze Report Buildera. W popupie nawigacja admina
        // nie istnieje, więc samo szukanie linku do /admin/ dawało fałszywy wynik.
        var path = window.location.pathname || '';
        var administrativePage =
            document.body.classList.contains('path-admin') ||
            document.body.classList.contains('pagelayout-popup') ||
            (document.body.id || '').indexOf('page-admin-') === 0 ||
            path.indexOf('/admin/') !== -1 ||
            path.indexOf('/reportbuilder/') !== -1;

        var isAdmin = administrativePage || Boolean(document.querySelector(
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

            loadMyCoursesTree();
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
