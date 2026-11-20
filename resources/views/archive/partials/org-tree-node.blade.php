{{-- One branch of the self-building Org Chart. Needs $node (from OrgStructure::build()).
     Every person gets the same full-size card, side by side (explicit request,
     2026-11-20: a team of 4 was a compact list while a team of 2 had big cards). --}}
<li>
    @include('archive.partials.org-tree-card', ['node' => $node, 'compact' => false])
    @if(! empty($node['children']))
    <ul>
        @foreach($node['children'] as $k)
            @include('archive.partials.org-tree-node', ['node' => $k])
        @endforeach
    </ul>
    @endif
</li>
