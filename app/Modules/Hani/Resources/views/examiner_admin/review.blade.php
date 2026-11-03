@extends('core::layouts.app')

@section('title', 'Pool Review List')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Pool Review List"
        subtitle="What an import found for you to check. CGS's sheet named a student an examiner is holding; UResearch records it here and never invents a nomination from it.">
        <a href="{{ route('examiner-admin.index') }}" class="btn-secondary">Back to the pool</a>
    </x-core::page-header>

    <div class="card card-wide" style="max-width: none;">
        @if ($items->isEmpty())
            <p class="empty-state">Nothing to check.</p>
        @else
            <table class="data-table">
                <thead><tr><th>Examiner</th><th>What</th><th>Detail</th><th>Raised</th><th></th></tr></thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->examiner?->name }}</td>
                            <td>{{ $item->kindLabel() }}</td>
                            <td>{{ $item->detail }}</td>
                            <td>{{ $item->created_at->format('j M Y') }}</td>
                            <td>
                                <form method="POST" action="{{ route('examiner-import.resolve', $item) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary">Mark as checked</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
