const loginForm = document.querySelector('#login-form');
if (loginForm) {
    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const errorNode = document.querySelector('#login-error');
        const formData = new FormData(loginForm);
        const csrf = formData.get('_csrf');

        const response = await fetch(loginForm.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf
            },
            body: formData,
            credentials: 'same-origin'
        });

        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            errorNode.textContent = payload.message || 'Login failed';
            return;
        }

        window.location.href = payload.redirect;
    });
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/greenfield/public/sw.js').catch(() => {});
    });
}
