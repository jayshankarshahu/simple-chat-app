const AJAX_ENDPOINT = '/ajax';
const UPLOADS_PATH = '/uploads';
const debounce = (callback, wait) => {
    let timeoutId = null;
    return (...args) => {
        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(callback, wait, ...args);
    };
};
const NO_PROFILE_IMAGE = 'no-profile.jpeg';
const RELATIVE_TIME_SELECTOR = 'relative_time';
const RELATIVE_TIME_HOLDER = 'data-timestamp';
const IS_MOBILE_DEVICE = window.innerWidth < 1024;
function show_toaster(message, success) {

    success ? console.log(message) : console.warn(message);

}

function getCookie(cname) {
    let name = cname + "=";
    let decodedCookie = decodeURIComponent(document.cookie);
    let ca = decodedCookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) == ' ') {
            c = c.substring(1);
        }
        if (c.indexOf(name) == 0) {
            return c.substring(name.length, c.length);
        }
    }
    return "";
}

function ajax_request(endpoint, options, callback, error_callback = null) {

    fetch(endpoint, options)
        .then(r => r.json())
        .then(callback)
        .catch(e => {

            if (error_callback) {
                error_callback(e)
            } else {
                show_toaster(e, false);
            }

        })

}

// in seconds
const units = {
    year: 24 * 60 * 60 * 1000 * 365,
    month: 24 * 60 * 60 * 1000 * 365 / 12,
    day: 24 * 60 * 60 * 1000,
    hour: 60 * 60 * 1000,
    minute: 60 * 1000,
    second: 1000
}

const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

function getRelativeTime( time ) {

    var elapsed = time - Date.now();
    
    for (var u in units) {
        
        if (Math.abs(elapsed) > units[u] || u == 'second') {
            return rtf.format(Math.round(elapsed / units[u]), u);
        }

    }
        
}

function set_all_relative_times() {

    document.querySelectorAll(`[${RELATIVE_TIME_SELECTOR}]`).forEach( e => {

        if( e.dataset.timestamp ) {
            e.textContent = getRelativeTime(e.dataset.timestamp);
        }

    } );

}

setInterval(set_all_relative_times, 5000);
