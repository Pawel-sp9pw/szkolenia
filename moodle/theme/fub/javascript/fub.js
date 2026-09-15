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
                // W dropdownie Moodle czasem data-action znajduje się na linku
                // wewnątrz <li>. Usuwamy cały element menu, jeśli jest dostępny.
                var menuitem = element.closest('li, .dropdown-item');
                if (menuitem && menuitem !== element && menuitem.contains(element)) {
                    menuitem.remove();
                } else {
                    element.remove();
                }
            });
        });

        // Fallback dla elementów filtra bez stabilnych data-attributes.
        document.querySelectorAll('.block_myoverview .dropdown-menu a, .block_myoverview .dropdown-menu button').forEach(function(element) {
            var text = (element.textContent || '').trim().toLowerCase();
            if (text === 'usunięte z widoku' || text === 'courses removed from view' || text === 'removed from view') {
                var parent = element.closest('li');
                (parent || element).remove();
            }
        });
    };

    removeCourseHidingControls();

    // Course overview jest renderowany i odświeżany asynchronicznie, więc
    // pilnujemy również kontrolek doładowanych po starcie strony.
    var observer = new MutationObserver(function() {
        removeCourseHidingControls();
    });
    observer.observe(document.body, {childList: true, subtree: true});

    if (!document.body.classList.contains('pagelayout-login')) {
        return;
    }

    var signup = document.querySelector('.login-signup a');
    if (signup) {
        signup.textContent = 'Zarejestruj się';
        signup.classList.remove('btn-secondary');
        signup.classList.add('btn-primary', 'btn-lg', 'w-100', 'fub-signup-button');
        return;
    }

    // Fallback: na stronie logowania przycisk rejestracji ma być widoczny
    // nawet jeśli szablon motywu nadrzędnego nie wyrenderował kontenera.
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
