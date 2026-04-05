/**
 * Loaded first in the GrapesJS canvas iframe, before any other scripts.
 *
 * Responsibility: inject Tailwind CDN into the canvas iframe and keep it
 * alive against GrapesJS's loadProjectData() head re-renders.
 *
 * The MutationObserver cascade guard lives in website-builder.js (parent
 * page context), because GrapesJS creates its ComponentView observers using
 * the parent window's MutationObserver, not the iframe's.
 *
 * Problem: GrapesJS treats <head data-gjs-type="head"> as a managed component.
 * When loadProjectData() fires, GrapesJS re-renders the head and strips any
 * dynamically-appended <script> tags that are not in canvas.scripts.
 *
 * Solution: inject immediately AND watch for removal using a native
 * MutationObserver, re-injecting whenever GrapesJS removes the Tailwind
 * script. Also retry with delays to survive the initial render cycle.
 */
(function () {
    var Real = window.MutationObserver;

    function inject() {
        if (!document.querySelector('script[src*="tailwindcss"]')) {
            var s = document.createElement('script');
            s.src = 'https://cdn.tailwindcss.com';
            document.head.appendChild(s);
        }
    }

    // Inject immediately, after rAF, and after delays to survive
    // GrapesJS's initial loadProjectData() head re-render.
    inject();
    requestAnimationFrame(inject);
    setTimeout(inject, 300);
    setTimeout(inject, 800);
    setTimeout(inject, 2000);

    // Watch for GrapesJS removing the Tailwind script from <head>
    // and re-inject. Use Real (native MO) to bypass any wrapper.
    var headWatcher = new Real(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var removed = mutations[i].removedNodes;
            for (var j = 0; j < removed.length; j++) {
                if (removed[j].src && removed[j].src.indexOf('tailwindcss') !== -1) {
                    inject();
                    return;
                }
            }
        }
    });
    headWatcher.observe(document.head, { childList: true });
}());
