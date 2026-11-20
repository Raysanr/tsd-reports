{{-- One branch of the self-building Org Chart. Needs $node (from OrgStructure::build()).
     Every person gets the same full-size card, side by side (explicit request,
     2026-11-20). A 'group' node is several people drawn as one box, all
     connected to what's under them (a team's "Show under these people"). --}}
<li>
    @if($node['type'] === 'group')
        <div class="org-group">
            @foreach($node['members'] as $member)
                @include('archive.partials.org-tree-card', ['node' => $member, 'compact' => false])
            @endforeach
        </div>
    @else
        @include('archive.partials.org-tree-card', ['node' => $node, 'compact' => false])
    @endif
    @if(! empty($node['children']))
    <ul>
        @foreach($node['children'] as $k)
            @include('archive.partials.org-tree-node', ['node' => $k])
        @endforeach
    </ul>
    @endif
</li>
