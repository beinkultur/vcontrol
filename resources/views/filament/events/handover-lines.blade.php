{{-- Übergebene Artikel in der Ansicht --}}
<table class="vc-lines">
    <thead>
        <tr><th>Kategorie</th><th>Artikel</th></tr>
    </thead>
    <tbody>
        @foreach ($protocol->items as $item)
            <tr><td>{{ $item->category_name }}</td><td>{{ $item->item_name }}</td></tr>
        @endforeach
    </tbody>
</table>
