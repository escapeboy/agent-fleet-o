<div
    x-data="websiteEditor(@js($page->grapes_json), @js($page->exported_html))"
    x-init="init()"
>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Top bar --}}
    <div class="mb-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('websites.show', $website) }}" class="text-sm text-gray-400 hover:text-gray-600">
                ← {{ $website->name }}
            </a>
            <span class="text-gray-300">/</span>
            <span class="text-sm font-medium text-gray-700">{{ $page->title }}</span>
        </div>
        <div class="flex items-center gap-2">
            @if($page->exported_html)
                <a href="{{ route('websites.pages.preview', [$website, $page]) }}" target="_blank"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                    Preview
                </a>
            @endif
            <button @click="save()"
                class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                Save
            </button>
            <button wire:click="publishPage"
                class="rounded-lg bg-green-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                Publish
            </button>
        </div>
    </div>

    <div class="flex gap-6">
        {{-- GrapesJS canvas --}}
        <div class="flex-1">
            <div wire:ignore id="gjs" style="min-height:600px;border:1px solid #e5e7eb;border-radius:0.5rem;overflow:hidden;"></div>
        </div>

        {{-- Settings sidebar --}}
        <div class="w-72 shrink-0">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="mb-4 text-sm font-semibold text-gray-900">Page Settings</h3>

                <div class="space-y-4">
                    <x-form-input wire:model="title" label="Title" placeholder="Page title" />
                    <x-form-input wire:model="slug" label="Slug" placeholder="page-slug" />

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">Type</label>
                        <select wire:model="pageType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                            <option value="product">Product</option>
                            <option value="landing">Landing</option>
                        </select>
                    </div>

                    <button wire:click="saveSettings"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Save Settings
                    </button>

                    <div class="border-t border-gray-200 pt-4">
                        <button @click="save()"
                            class="w-full rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Save Content
                        </button>
                    </div>

                    <div>
                        <button wire:click="publishPage"
                            class="w-full rounded-lg bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                            Publish Page
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css">

<script>
function websiteEditor(initialProjectData, exportedHtml) {
    let editor;
    return {
        init() {
            editor = grapesjs.init({
                container: '#gjs',
                storageManager: false,
                height: '600px',
                width: 'auto',
                blockManager: {
                    appendTo: '#blocks',
                    blocks: [
                        {
                            id: 'fleetq-form',
                            label: 'Contact Form',
                            category: 'FleetQ',
                            content: `<form class="fleetq-form" data-form-id="contact">
                                <input type="text" name="name" placeholder="Your name" style="display:block;width:100%;margin-bottom:8px;padding:8px;border:1px solid #ccc;border-radius:4px">
                                <input type="email" name="email" placeholder="Your email" style="display:block;width:100%;margin-bottom:8px;padding:8px;border:1px solid #ccc;border-radius:4px">
                                <textarea name="message" placeholder="Your message" rows="4" style="display:block;width:100%;margin-bottom:8px;padding:8px;border:1px solid #ccc;border-radius:4px"></textarea>
                                <button type="submit" style="background:#4f46e5;color:#fff;padding:8px 20px;border:none;border-radius:4px;cursor:pointer">Send</button>
                            </form>`,
                        },
                        {
                            id: 'fleetq-chatbot',
                            label: 'Chatbot Widget',
                            category: 'FleetQ',
                            content: `<div class="fleetq-chatbot-embed" data-chatbot-id="YOUR_CHATBOT_ID">
                                <!-- FleetQ chatbot will be injected here -->
                            </div>`,
                        },
                        {
                            id: 'fleetq-hero',
                            label: 'Hero Section',
                            category: 'FleetQ',
                            content: `<section style="padding:80px 20px;text-align:center;background:#f8fafc">
                                <h1 style="font-size:3rem;font-weight:bold;margin-bottom:16px">Your Headline Here</h1>
                                <p style="font-size:1.25rem;color:#64748b;margin-bottom:32px">A compelling subheadline that explains your value proposition.</p>
                                <a href="#" style="background:#4f46e5;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600">Get Started</a>
                            </section>`,
                        },
                        {
                            id: 'fleetq-features',
                            label: 'Features Grid',
                            category: 'FleetQ',
                            content: `<section style="padding:60px 20px">
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:32px;max-width:960px;margin:0 auto">
                                    <div style="text-align:center;padding:24px"><h3 style="font-weight:600;margin-bottom:8px">Feature One</h3><p style="color:#64748b">Description of this feature.</p></div>
                                    <div style="text-align:center;padding:24px"><h3 style="font-weight:600;margin-bottom:8px">Feature Two</h3><p style="color:#64748b">Description of this feature.</p></div>
                                    <div style="text-align:center;padding:24px"><h3 style="font-weight:600;margin-bottom:8px">Feature Three</h3><p style="color:#64748b">Description of this feature.</p></div>
                                </div>
                            </section>`,
                        },
                        {
                            id: 'fleetq-pricing',
                            label: 'Pricing Card',
                            category: 'FleetQ',
                            content: `<section style="padding:60px 20px;text-align:center">
                                <div style="display:inline-block;border:1px solid #e2e8f0;border-radius:12px;padding:40px;max-width:320px">
                                    <h3 style="font-size:1.5rem;font-weight:bold;margin-bottom:8px">Pro Plan</h3>
                                    <p style="font-size:3rem;font-weight:bold;margin-bottom:24px">$49<span style="font-size:1rem;color:#64748b">/mo</span></p>
                                    <a href="#" style="display:block;background:#4f46e5;color:#fff;padding:12px;border-radius:8px;text-decoration:none;font-weight:600">Get Started</a>
                                </div>
                            </section>`,
                        },
                        {
                            id: 'fleetq-testimonials',
                            label: 'Testimonials',
                            category: 'FleetQ',
                            content: `<section style="padding:60px 20px;background:#f8fafc">
                                <blockquote style="max-width:600px;margin:0 auto;text-align:center">
                                    <p style="font-size:1.25rem;font-style:italic;color:#334155">"This product changed everything for our team. Highly recommended!"</p>
                                    <footer style="margin-top:16px;color:#64748b">— Jane Doe, CEO at Acme Corp</footer>
                                </blockquote>
                            </section>`,
                        },
                    ],
                },
            });

            // Load existing content
            if (initialProjectData && initialProjectData.pages) {
                editor.loadProjectData(initialProjectData);
            } else if (exportedHtml) {
                editor.setComponents(exportedHtml);
            }

            // Listen for save notification from Livewire
            this.$wire.on('saved', () => {
                const el = document.createElement('div');
                el.textContent = 'Saved';
                el.style.cssText = 'position:fixed;top:16px;right:16px;background:#22c55e;color:#fff;padding:8px 16px;border-radius:8px;z-index:9999;font-size:14px';
                document.body.appendChild(el);
                setTimeout(() => el.remove(), 2000);
            });
        },

        save() {
            const html = editor.getHtml();
            const css = editor.getCss();
            const json = editor.getProjectData();
            this.$wire.saveContent(json, html, css);
        },
    };
}
</script>
@endpush
