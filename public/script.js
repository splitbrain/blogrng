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

    /** review the suggestions one at a time in a preview window, prefetch the next one in a small window */
    (function () {
        const review = document.getElementById('review');
        if (!review) return;

        const suggestions = JSON.parse(review.dataset.suggestions);
        const queue = [];
        let current = 0;
        let running = false;
        let preview = null;
        let prefetch = null;

        /**
         * Find an element in the review section
         *
         * @param {string} selector
         * @returns {HTMLElement}
         */
        function $(selector) {
            return review.querySelector(selector);
        }

        /**
         * Load a URL in the given window, open a new window if it does not exist
         *
         * Opening a window needs a user interaction and browsers allow only one new window per interaction.
         * A blocked window stays null and is opened at a later interaction.
         *
         * @param {Window|null} win the window opened before
         * @param {string} url
         * @param {string} name the window name
         * @param {string} features the window features
         * @returns {Window|null}
         */
        function navigate(win, url, name, features) {
            if (!/^https?:\/\//.test(url)) return win;
            if (win && !win.closed) {
                win.location = url;
                return win;
            }
            return window.open(url, name, features);
        }

        /**
         * Show the current suggestion in the preview window and the next one in the prefetch window
         */
        function load() {
            const feed = suggestions[current];
            const next = suggestions[current + 1];

            if (feed) {
                preview = navigate(preview, feed.homepage, 'blogreview-preview', 'popup,width=1200,height=900');
            }
            if (next) {
                prefetch = navigate(prefetch, next.homepage, 'blogreview-prefetch', 'popup,width=100,height=100,left=0,top=0');
            } else if (prefetch) {
                prefetch.close();
            }
        }

        /**
         * Show the current suggestion in the review section
         */
        function render() {
            const feed = suggestions[current];

            $('.review-count').textContent = suggestions.length - current;
            $('.review-current').hidden = !feed;
            $('.review-done').hidden = !!feed;
            if (!feed) return;

            $('.review-title').textContent = feed.feedtitle;
            $('.review-homepage').textContent = feed.homepage;
            $('.review-homepage').href = feed.homepage;
            $('.review-feedurl').textContent = feed.feedurl;
            $('.review-feedurl').href = feed.feedurl;
        }

        /**
         * Apply a decision to the current suggestion and continue with the next one
         *
         * @param {string} action approve, reject or skip
         */
        function decide(action) {
            const feed = suggestions[current];
            if (!feed) return;

            if (action !== 'skip') enqueue(action, feed);
            current++;
            render();
            if (preview) load();
        }

        /**
         * Add an action to the background queue
         *
         * @param {string} action approve or reject
         * @param {object} feed the suggestion
         */
        function enqueue(action, feed) {
            const li = document.createElement('li');
            li.textContent = `${action}: ${feed.feedtitle} …`;
            $('.review-queue').append(li);

            queue.push({action, feed, li});
            work();
        }

        /**
         * Send the queued actions to the server one after the other
         */
        async function work() {
            if (running) return;
            running = true;

            while (queue.length) {
                const entry = queue[0];
                try {
                    const response = await fetch('review', {
                        method: 'POST',
                        body: new URLSearchParams({
                            action: entry.action,
                            feedid: entry.feed.feedid,
                            feedurl: entry.feed.feedurl,
                        }),
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error);
                    entry.li.remove();
                } catch (e) {
                    fail(entry, e.message);
                }
                queue.shift();
            }

            running = false;
        }

        /**
         * Show a failed action with its error and a button to try it again
         *
         * @param {object} entry the queue entry
         * @param {string} error the error message
         */
        function fail(entry, error) {
            const button = document.createElement('button');
            button.textContent = 'Retry';
            button.addEventListener('click', () => {
                entry.li.remove();
                enqueue(entry.action, entry.feed);
            });

            entry.li.textContent = `${entry.action} failed: ${entry.feed.feedtitle}: ${error} `;
            entry.li.append(button);
        }

        $('.review-start').addEventListener('click', load);

        review.querySelectorAll('button[data-action]').forEach(button => {
            button.addEventListener('click', () => decide(button.dataset.action));
        });

        document.addEventListener('keydown', event => {
            if (event.ctrlKey || event.metaKey || event.altKey || event.repeat) return;
            const action = {a: 'approve', r: 'reject', s: 'skip'}[event.key];
            if (action) decide(action);
        });

        window.addEventListener('beforeunload', event => {
            if (queue.length) event.preventDefault();
        });

        render();
    })();

});

 if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js')
      .then(function(reg){
        console.log("Service worker registered.");
     }).catch(function(err) {
        console.log("Service worker not registered. This happened:", err)
    });
 }
