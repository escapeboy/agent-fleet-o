/**
 * GrapesJS website builder Alpine.js component.
 *
 * Data is passed via window.__builderData (set by the Blade template before
 * Alpine boots) to keep large PHP-generated JSON out of inline scripts.
 * External file avoids inline-script injection from page-level MCP helpers.
 */

/**
 * Cascade guard for GrapesJS ComponentView MutationObservers.
 *
 * Root cause of browser freeze when selecting elements:
 *   GrapesJS 0.21.13 creates one MutationObserver per ComponentView, using
 *   the PARENT window's MutationObserver (not the iframe's). A page with
 *   370+ components creates 370+ active observers. When you select an element,
 *   each observer fires sequentially; each callback calls model.set() →
 *   Backbone fires 'change' → DOM re-renders → more observers fire →
 *   exponential sequential cascade. The browser never recovers.
 *
 * Fix:
 *   Replace window.MutationObserver with a debounced version BEFORE
 *   grapesjs.init() so all ComponentView observers use the guarded version.
 *   All callbacks from a single event are batched into one 50ms window.
 *   After 50ms the batch runs; any cascade triggered by that batch waits
 *   another 50ms. The cascade converges because the DOM stabilises between
 *   windows instead of spiralling synchronously.
 *
 *   Effect on other page code (Livewire, Alpine):
 *   Alpine reactivity is Proxy-based, not MO-based — unaffected.
 *   Livewire's morphing MO sees a ~50ms delay on server-rendered updates,
 *   which is imperceptible compared to the network roundtrip.
 */
(function installGrapesCascadeGuard() {
    if (window.__grapesCascadeGuard) { return; }

    var Real = window.MutationObserver;
    window.__grapesCascadeGuardReal = Real;

    // Map from GuardedMO instance → { callback, records[] }
    // All observers share one flush timer so the whole batch runs together.
    var pending = new Map();
    var flushTimer = null;

    function scheduleFlush() {
        if (!flushTimer) {
            flushTimer = setTimeout(function () {
                flushTimer = null;
                var entries = Array.from(pending.entries());
                pending.clear();
                for (var i = 0; i < entries.length; i++) {
                    try {
                        entries[i][1].callback(entries[i][1].records, entries[i][0]);
                    } catch (e) {
                        // swallow — don't let one bad callback block the rest
                    }
                }
                // New entries added during the flush get another window.
                if (pending.size > 0) { scheduleFlush(); }
            }, 50);
        }
    }

    function GuardedMO(callback) {
        var self = this;

        var guardedCallback = function (records, observer) {
            if (pending.has(self)) {
                // Merge records from repeated fires before the window drains.
                var item = pending.get(self);
                item.records = item.records.concat(Array.from(records));
            } else {
                pending.set(self, { callback: callback, records: Array.from(records) });
            }
            scheduleFlush();
        };

        var obs = new Real(guardedCallback);
        this._real = obs;
        this.observe = obs.observe.bind(obs);
        this.disconnect = function () {
            pending.delete(self);
            return obs.disconnect();
        };
        this.takeRecords = obs.takeRecords.bind(obs);
    }

    GuardedMO.prototype = Real.prototype;
    window.MutationObserver = GuardedMO;
    window.__grapesCascadeGuard = true;
}());

window.websiteBuilder = function () {
    const d = window.__builderData || {};
    const initialJson  = d.json || {};
    const initialHtml  = d.html || '';
    const initialCss   = d.css  || '';
    const blocks       = d.blocks || {};
    const previewUrl   = d.previewUrl || null;

    return {
        editor: null,
        device: 'desktop',
        saving: false,
        showSaved: false,

        init() {
            // Guard against double-initialisation (e.g. Livewire re-render or
            // Alpine re-mount after WebSocket reconnect). A second GrapesJS instance
            // sharing #gjs-blocks-panel / #gjs-styles-panel produces doubled blocks
            // and crashes the renderer on element selection.
            if (this.editor) {
                return;
            }

            this.editor = grapesjs.init({
                container: '#gjs',
                height: '100%',
                storageManager: false,
                blockManager: {
                    appendTo: '#gjs-blocks-panel',
                },
                styleManager: {
                    appendTo: '#gjs-styles-panel',
                },
                traitManager: {
                    appendTo: '#gjs-traits-panel',
                },
                deviceManager: {
                    devices: [
                        { name: 'Desktop', width: '' },
                        { name: 'Tablet', width: '768px', widthMedia: '992px' },
                        { name: 'Mobile', width: '320px', widthMedia: '480px' },
                    ],
                },
                canvas: {
                    // builder-canvas-init.js loads first in the iframe:
                    // Injects Tailwind CDN and watches for GrapesJS stripping it
                    // during loadProjectData() head re-renders.
                    scripts: ['/js/builder-canvas-init.js?v=5'],
                    styles: [],
                },
                plugins: [],
            });

            if (initialJson && Object.keys(initialJson).length > 0) {
                this.editor.loadProjectData(initialJson);
            } else if (initialHtml) {
                let bodyContent = initialHtml;
                const bodyMatch = initialHtml.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
                if (bodyMatch) {
                    bodyContent = bodyMatch[1];
                }
                this.editor.setComponents(bodyContent);
                if (initialCss) {
                    this.editor.setStyle(initialCss);
                }
            }

            const bm = this.editor.BlockManager;
            Object.entries(blocks).forEach(([id, block]) => {
                bm.add(id, {
                    label: block.label,
                    category: block.category || 'General',
                    media: block.media || '',
                    content: block.content,
                });
            });

        },

        setDevice(device) {
            this.device = device;
            const names = { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Mobile' };
            this.editor.setDevice(names[device]);
        },

        undo() {
            this.editor.UndoManager.undo();
        },

        redo() {
            this.editor.UndoManager.redo();
        },

        preview() {
            // Open the server-side preview route in a new tab.
            // The route serves the last-saved content with Tailwind CDN injected,
            // bypassing canvas CSS limitations entirely. Save before previewing
            // to see the latest changes.
            if (previewUrl) {
                window.open(previewUrl, '_blank');
            }
        },

        // Called automatically by Alpine.js when the component is unmounted.
        destroy() {
            if (this.editor) {
                this.editor.destroy();
                this.editor = null;
            }
        },

        saveContent() {
            this.saving = true;
            const projectData = this.editor.getProjectData();
            const html = this.editor.getHtml();
            const css = this.editor.getCss();

            this.$wire.save(projectData, html, css).then(() => {
                this.saving = false;
                this.showSaved = true;
                setTimeout(() => { this.showSaved = false; }, 3000);
            }).catch(() => {
                this.saving = false;
            });
        },
    };
};
