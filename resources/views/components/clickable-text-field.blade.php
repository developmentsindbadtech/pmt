@props([
    'label',
    'name',
    'value' => '',
    'placeholder' => '',
    'fieldClass' => '',
    'labelClass' => '',
    'mentionBoardId' => null,
])

@php
    $text = is_string($value) ? $value : '';
@endphp

<div x-data="{ editing: @js($text === '') }">
    <div class="flex items-center justify-between gap-2">
        <label class="{{ $labelClass }}">{{ $label }}</label>
        <button
            type="button"
            x-show="!editing"
            x-cloak
            @click="editing = true; $nextTick(() => $refs.field.focus())"
            class="text-[11px] font-medium text-blue-600 hover:underline"
        >Edit</button>
    </div>
    <p class="mt-0.5 text-[11px] leading-4 text-gray-400">Paste https://… or write [name](https://…). The link is clickable after you save.</p>
    <div
        x-show="!editing"
        @if($text === '') x-cloak @endif
        @click="if ($event.target.closest('a')) return; const sel = window.getSelection(); if (sel && sel.toString() !== '' && $el.contains(sel.anchorNode)) return; editing = true; $nextTick(() => $refs.field.focus())"
        class="mt-1 min-h-[7rem] w-full cursor-text whitespace-pre-wrap break-words rounded border border-transparent px-2.5 py-1.5 text-sm text-gray-900 hover:border-gray-200"
    >
        @if($text !== '')
            <x-rich-text :text="$text" />
        @else
            <span class="italic text-gray-400">{{ $placeholder }}</span>
        @endif
    </div>
    <textarea
        x-ref="field"
        x-show="editing"
        x-cloak
        name="{{ $name }}"
        rows="5"
        @if($mentionBoardId) data-mention-board-id="{{ $mentionBoardId }}" @endif
        class="js-mention-textarea {{ $fieldClass }} min-h-[7rem] resize-y"
        placeholder="{{ $placeholder }}"
    >{{ $text }}</textarea>
</div>
