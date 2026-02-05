@php
    $inGroup = $inGroup ?? false;
@endphp
<tr class="hover:bg-gray-50">
    @if($inGroup)
        <td class="w-10 px-3 py-2"></td>
        <td class="w-10 px-3 py-2"></td>
    @else
        <td class="w-10 px-3 py-2"></td>
    @endif
    @foreach($board->columns as $col)
        @php
            $val = $item->itemColumnValues->firstWhere('column_id', $col->id);
            $display = $val && is_array($val->value) ? ($val->value['text'] ?? $val->value['value'] ?? json_encode($val->value)) : ($val->value ?? '');
            if ($col->type === 'text' && $display === '' && $col->name === 'Name') {
                $display = $item->name;
            }
            $isStatus = ($col->type === 'status' || strtolower($col->name ?? '') === 'status');
            $pillClass = $isStatus && $statusPillClass ? $statusPillClass($display) : null;
        @endphp
        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900">
            @if($pillClass)
                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $pillClass }}">{{ $display ?: '—' }}</span>
            @else
                {{ $display ?: '—' }}
            @endif
        </td>
    @endforeach
    <td class="whitespace-nowrap px-4 py-3 text-right">
        <a href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', 'table')]) }}" class="text-blue-600 hover:text-blue-800">View</a>
        <form action="{{ route('items.destroy', [$board, $item]) }}" method="POST" class="inline ml-2" onsubmit="return confirm('Delete this task?');">
            @csrf
            @method('DELETE')
            <input type="hidden" name="view" value="table" />
            <button type="submit" class="text-red-600 hover:text-red-800" title="Delete task">Delete</button>
        </form>
    </td>
</tr>
