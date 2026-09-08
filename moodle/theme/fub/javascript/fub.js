document.addEventListener('DOMContentLoaded', function() {
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
