@php
    $linkClass = 'underline hover:text-gray-600 dark:hover:text-gray-300';
@endphp
<div class="text-center text-xs text-gray-400 dark:text-gray-500 py-4">
    <a href="https://github.com/AsteriaCreations/portico" target="_blank" rel="noopener" class="inline-block mb-2 opacity-60 hover:opacity-100">
        <img src="{{ asset('images/portico-logo.png') }}" alt="{{ __('Powered by Portico') }}" class="h-5 w-auto dark:hidden">
        <img src="{{ asset('images/portico-logo-white.png') }}" alt="{{ __('Powered by Portico') }}" class="hidden h-5 w-auto dark:block">
    </a>
    <p>
        {!! __(':app is free software, licensed under the :license. :source.', [
            'app' => e(config('app.name')),
            'license' => '<a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener" class="'.$linkClass.'">'.e(__('GNU Affero General Public License v3.0 or later')).'</a>',
            'source' => '<a href="https://github.com/AsteriaCreations/portico" target="_blank" rel="noopener" class="'.$linkClass.'">'.e(__('Source code')).'</a>',
        ]) !!}
    </p>
</div>
