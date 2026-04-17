@props(['href', 'active' => false, 'icon' => ''])

<a href="{{ $href }}"
   class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors
          {{ $active
              ? 'bg-(--color-sidebar-hover) text-white'
              : 'text-gray-400 hover:bg-(--color-sidebar-hover) hover:text-white' }}">
    @if($icon === 'chart-bar')
        <i class="fas fa-chart-bar h-5 w-5"></i>
    @elseif($icon === 'beaker')
        <i class="fas fa-flask-vial h-5 w-5"></i>
    @elseif($icon === 'puzzle-piece')
        <i class="fas fa-puzzle-piece h-5 w-5"></i>
    @elseif($icon === 'academic-cap')
        <i class="fas fa-graduation-cap h-5 w-5"></i>
    @elseif($icon === 'cpu-chip')
        <i class="fas fa-microchip h-5 w-5"></i>
    @elseif($icon === 'arrow-path')
        <i class="fas fa-arrows-rotate h-5 w-5"></i>
    @elseif($icon === 'shopping-bag')
        <i class="fas fa-bag-shopping h-5 w-5"></i>
    @elseif($icon === 'check-circle')
        <i class="fas fa-circle-check h-5 w-5"></i>
    @elseif($icon === 'heart')
        <i class="fas fa-heart h-5 w-5"></i>
    @elseif($icon === 'document-text')
        <i class="fas fa-file-lines h-5 w-5"></i>
    @elseif($icon === 'cog')
        <i class="fas fa-gear h-5 w-5"></i>
    @elseif($icon === 'user-group')
        <i class="fas fa-people-group h-5 w-5"></i>
    @elseif($icon === 'credit-card')
        <i class="fas fa-credit-card h-5 w-5"></i>
    @elseif($icon === 'shield-check')
        <i class="fas fa-shield-check h-5 w-5"></i>
    @elseif($icon === 'wrench-screwdriver')
        <i class="fas fa-screwdriver h-5 w-5"></i>
    @elseif($icon === 'key')
        <i class="fas fa-key h-5 w-5"></i>
    @elseif($icon === 'folder')
        <i class="fas fa-folder h-5 w-5"></i>
    @elseif($icon === 'circle-stack')
        <i class="fas fa-database h-5 w-5"></i>
    @elseif($icon === 'plug')
        <i class="fas fa-plug h-5 w-5"></i>
    @elseif($icon === 'envelope')
        <i class="fas fa-envelope h-5 w-5"></i>
    @elseif($icon === 'users')
        <i class="fas fa-users h-5 w-5"></i>
    @elseif($icon === 'lock-closed')
        <i class="fas fa-lock h-5 w-5"></i>
    @elseif($icon === 'chat-bubble-left-ellipsis')
        <i class="fas fa-message h-5 w-5"></i>
    @elseif($icon === 'chat-bubble-left-right')
        <i class="fas fa-comments h-5 w-5"></i>
    @elseif($icon === 'book-open')
        <i class="fas fa-book-open-reader h-5 w-5"></i>
    @elseif($icon === 'bell')
        <i class="fas fa-bell h-5 w-5"></i>
    @elseif($icon === 'bolt')
        <i class="fas fa-bolt h-5 w-5"></i>
    @elseif($icon === 'link')
        <i class="fas fa-link h-5 w-5"></i>
    @elseif($icon === 'code-branch')
        <i class="fas fa-code-branch h-5 w-5"></i>
    @elseif($icon === 'user-circle')
        <i class="fas fa-circle-user h-5 w-5"></i>
    @elseif($icon === 'paper-airplane')
        <i class="fas fa-paper-plane h-5 w-5"></i>
    @elseif($icon === 'squares-2x2')
        <i class="fas fa-th h-5 w-5"></i>
    @elseif($icon === 'identification')
        <i class="fas fa-id-card h-5 w-5"></i>
    @elseif($icon === 'scale')
        <i class="fas fa-scale-balanced h-5 w-5"></i>
    @elseif($icon === 'signal')
        <i class="fas fa-signal h-5 w-5"></i>
    @elseif($icon === 'play')
        <i class="fas fa-play h-5 w-5"></i>
    @elseif($icon === 'sparkles')
        <i class="fas fa-wand-magic-sparkles h-5 w-5"></i>
    @elseif($icon === 'share')
        <i class="fas fa-share-nodes h-5 w-5"></i>
    @elseif($icon === 'globe-alt')
        <i class="fas fa-globe h-5 w-5"></i>
    @elseif($icon === 'bug-ant')
        <i class="fas fa-bug h-5 w-5"></i>
    @elseif($icon === 'light-bulb')
        <i class="fas fa-lightbulb h-5 w-5"></i>
    @endif
    {{ $slot }}
</a>