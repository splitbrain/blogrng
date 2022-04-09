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
