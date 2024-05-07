<x-filament::page>
    @push('styles')
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="{{ asset('vendor/file-manager/css/file-manager.css') }}">
    @endpush

    <div style="height: 600px;">
        <div id="fm" style="height: 600px;"></div>
    </div>

    @push('scripts')
        <script src="{{ asset('vendor/file-manager/js/file-manager.js') }}"></script>
    @endpush
</x-filament::page>
