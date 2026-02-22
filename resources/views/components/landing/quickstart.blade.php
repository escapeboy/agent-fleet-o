<section class="bg-white py-16 sm:py-20">
    <div class="mx-auto max-w-3xl px-6 lg:px-8">
        <div class="text-center"
             x-data="{ shown: false }"
             x-intersect.once="shown = true"
             :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
             class="transition duration-600 ease-out">
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">Up and Running in 5 Minutes</h2>
            <p class="mt-3 text-base text-gray-600">One command to install. The setup wizard handles the rest.</p>
            <div class="mt-8 overflow-hidden rounded-xl border border-gray-200 bg-gray-900 shadow-lg">
                <div class="flex items-center gap-2 border-b border-gray-700 px-4 py-2.5">
                    <div class="h-2.5 w-2.5 rounded-full bg-red-400"></div>
                    <div class="h-2.5 w-2.5 rounded-full bg-yellow-400"></div>
                    <div class="h-2.5 w-2.5 rounded-full bg-green-400"></div>
                    <span class="ml-2 text-xs text-gray-500">Terminal</span>
                </div>
                <div class="p-5 text-left">
                    <code class="block text-sm leading-7 text-gray-300"><span class="select-none text-gray-500">$ </span><span class="text-green-400">git clone</span> https://github.com/escapeboy/agent-fleet-o.git</code>
                    <code class="block text-sm leading-7 text-gray-300"><span class="select-none text-gray-500">$ </span><span class="text-green-400">cd</span> agent-fleet-o</code>
                    <code class="block text-sm leading-7 text-gray-300"><span class="select-none text-gray-500">$ </span><span class="text-green-400">make install</span></code>
                    <code class="mt-2 block text-sm leading-7 text-gray-500"># Docker builds, migrations run, setup wizard starts</code>
                    <code class="block text-sm leading-7 text-gray-500"># Open http://localhost:8080 and you're ready</code>
                </div>
            </div>
        </div>
    </div>
</section>
