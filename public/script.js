document.addEventListener('DOMContentLoaded', event => {
    /** auto reload the personal history after the stumble button was clicked */
    (function () {
        const button = document.getElementById('stumble');
        const output = document.getElementById('seen');

        async function reloadSeen() {
            const data = await fetch('seen');
            output.innerHTML = await data.text();
        }

        if (button) {
            button.addEventListener('click', event => {
                window.setTimeout(reloadSeen, 1000);
            });
        }
    })();

    /** auto open a details block when a hash was passed in the URL */
    (function () {
        if (!window.location.hash) return;
        const target = document.getElementById(window.location.hash);
        if (!target) return;
        const details = target.closest('details');
        if (!details) return;
        if (!details.open) details.open = true;
    })();

    /** opening/closing a details block adds a hash to the URL */
    (function () {
        document.querySelectorAll('summary').forEach(el => el.addEventListener('click', event => {
            if (!el.id) return;
            const details = el.closest('details');

            if (details.open) {
                history.replaceState({}, '', window.location.pathname)
            } else {
                history.replaceState({}, '', `#${el.id}`)
            }
        }));
    })();

});
