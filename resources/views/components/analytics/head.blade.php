{{-- Google Analytics 4, Consent Mode v2 BASIC. Only defines gtag and the consent logic: gtag.js is
     injected after the visitor clicks Accept, so no Google host is contacted before that, and
     nothing happens on Reject. Withdrawal (the "Cookie settings" link reopens the banner) sets
     ga-disable-<ID> and deletes the _ga cookies. The choice is kept in localStorage with a version
     and a timestamp. Renders nothing when GOOGLE_ANALYTICS_ID is unset. Pairs with
     <x-analytics.banner /> (early in <body>) and <x-analytics.settings-link />. --}}
@php($gaId = (string) config('services.google_analytics.id'))
@if($gaId !== '')
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500});
        (function () {
            var ID = @json($gaId), KEY = 'cookie_consent', YEAR = 365 * 24 * 60 * 60 * 1000, loaded = false;
            window['ga-disable-' + ID] = true;
            function saved() {
                try {
                    var r = JSON.parse(localStorage.getItem(KEY));
                    if (r && r.v === 1 && typeof r.analytics === 'boolean' && Date.now() - Date.parse(r.at) < YEAR) { return r.analytics; }
                } catch (e) {}
                return null;
            }
            function save(analytics) {
                try { localStorage.setItem(KEY, JSON.stringify({analytics: analytics, v: 1, at: new Date().toISOString()})); } catch (e) {}
            }
            function clearCookies() {
                var parts = location.hostname.split('.'), domains = [''];
                for (var i = 0; i < parts.length - 1; i++) { domains.push('; domain=' + parts.slice(i).join('.')); }
                document.cookie.split(';').map(function (c) { return c.split('=')[0].trim(); })
                    .filter(function (n) { return n === '_ga' || n.indexOf('_ga_') === 0; })
                    .forEach(function (n) {
                        domains.forEach(function (d) { document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + d; });
                    });
            }
            function grant() {
                window['ga-disable-' + ID] = false;
                gtag('consent', 'update', {analytics_storage: 'granted'});
                if (loaded) { return; }
                loaded = true;
                gtag('js', new Date());
                gtag('config', ID, {page_location: location.origin + location.pathname, page_referrer: document.referrer ? new URL(document.referrer).origin + '/' : ''});
                var s = document.createElement('script');
                s.async = true;
                s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ID);
                document.head.appendChild(s);
            }
            function revoke() {
                window['ga-disable-' + ID] = true;
                if (loaded) { gtag('consent', 'update', {analytics_storage: 'denied'}); }
                clearCookies();
            }
            function banner() { return document.querySelector('[data-cookie-banner]'); }
            if (saved() === true) { grant(); }
            document.addEventListener('DOMContentLoaded', function () {
                if (saved() === null && banner()) { banner().hidden = false; }
            });
            document.addEventListener('click', function (e) {
                var t = e.target.closest ? e.target.closest('[data-cookie-choice], [data-cookie-settings]') : null, b = banner();
                if (!t || !b) { return; }
                if (t.hasAttribute('data-cookie-settings')) {
                    b.hidden = false;
                    var first = b.querySelector('button');
                    if (first) { first.focus(); }
                    return;
                }
                var accept = t.getAttribute('data-cookie-choice') === 'accept';
                save(accept);
                if (accept) { grant(); } else { revoke(); }
                b.hidden = true;
            });
        })();
    </script>
@endif
