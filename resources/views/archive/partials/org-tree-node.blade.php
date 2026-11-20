{{-- One branch of the self-building Org Chart. Needs $node (from OrgStructure::build()).
     Plain members (no one under them) are stacked in one column so a team of 30
     TSAs stays narrow instead of a 30-card-wide row. --}}
@php
    $kids = collect($node['children']);
    $isLeaf = fn ($k) => $k['type'] === 'person' && empty($k['children']) && ! $k['isHead'];
    $leaves = $kids->filter($isLeaf);
    $branches = $kids->reject($isLeaf);
    $stack = $leaves->count() > 3 || ($branches->isNotEmpty() && $leaves->count() > 1);
@endphp
<li>
    @include('archive.partials.org-tree-card', ['node' => $node, 'compact' => false])
    @if($kids->isNotEmpty())
    <ul>
        @foreach($stack ? $branches : $kids as $k)
            @include('archive.partials.org-tree-node', ['node' => $k])
        @endforeach
        @if($stack)
        <li>
            <div class="org-stack {{ $leaves->count() > 8 ? 'org-stack-2' : '' }}">
                @foreach($leaves as $k)
                    @include('archive.partials.org-tree-card', ['node' => $k, 'compact' => true])
                @endforeach
            </div>
        </li>
        @endif
    </ul>
    @endif
</li>
