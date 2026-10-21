@props(['text' => null])

{!! \App\Support\TextFormatter::render(is_string($text) ? $text : null) !!}
