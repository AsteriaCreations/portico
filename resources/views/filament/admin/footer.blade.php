@php
    $linkClass = 'underline hover:text-gray-600 dark:hover:text-gray-300';
@endphp
<p class="text-center text-xs text-gray-400 dark:text-gray-500 py-4">
    {!! __(':app is free software, licensed under the :license. :source.', [
        'app' => e(config('app.name')),
        'license' => '<a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener" class="'.$linkClass.'">'.e(__('GNU Affero General Public License v3.0 or later')).'</a>',
        'source' => '<a href="https://github.com/AsteriaCreations/portico" target="_blank" rel="noopener" class="'.$linkClass.'">'.e(__('Source code')).'</a>',
    ]) !!}
</p>
