<div class="flex items-center gap-2">
    <div class="relative h-6 w-6 shrink-0 overflow-hidden rounded-full bg-gray-300">
        @if($photoUrl)
            <img src="{{ $photoUrl }}" alt="{{ $user->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        @endif
        <div class="{{ $photoUrl ? 'hidden' : 'flex' }} h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
            {{ $initials }}
        </div>
    </div>
    <span class="text-sm text-gray-600">{{ $user->name }}</span>
</div>
